// Package worldtext is a client for the World Text HTTP API, spec version 3.0.
//
// Authentication is bearer-only: every request carries "Authorization: Bearer
// <token>". API 3.0 has no query-string id/key authentication and this package
// does not implement one.
//
//	client, err := worldtext.NewClient(worldtext.DefaultBaseURL, token)
//	if err != nil {
//		log.Fatal(err) // the message never contains the token
//	}
//	credits, err := client.GetCredits(ctx)
//
// Every failure is a *worldtext.Error (or a more specific type embedding one)
// carrying the HTTP status and API error code; see Errors.
package worldtext

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// DefaultBaseURL is the production API root.
const DefaultBaseURL = "https://sms.world-text.com/v2.0"

// DefaultTimeout bounds a single request when none is configured.
const DefaultTimeout = 30 * time.Second

// maxResponseBytes caps how much of a response body is read, so a misbehaving
// server cannot exhaust memory.
const maxResponseBytes = 8 << 20

// Logf receives diagnostic messages, such as the method and path of each
// request. It is never passed the token. A nil Logf silences logging.
type Logf func(format string, args ...any)

// Client talks to the World Text API. It is safe for concurrent use.
//
// Create one with NewClient. The token is held in an unexported field and is
// never printed by any method, error or log line.
type Client struct {
	// BaseURL is the API root the client sends requests to, without a
	// trailing slash.
	BaseURL string
	// HTTPClient performs requests. It defaults to a client with Timeout;
	// set it to share transports or plug in instrumentation.
	HTTPClient *http.Client
	// Logf, when set, receives one line per request: method and path only.
	Logf Logf

	token string
}

// NewClient returns a client for token, talking to baseURL (pass
// DefaultBaseURL for production, or a mock's URL in tests).
//
// It fails with a *ConfigError when the token or base URL is missing or the
// base URL is not an absolute http(s) URL. The error never contains the token.
func NewClient(baseURL, token string) (*Client, error) {
	if token == "" {
		return nil, &ConfigError{Msg: "a token is required"}
	}
	if baseURL == "" {
		return nil, &ConfigError{Msg: "a base URL is required"}
	}
	u, err := url.Parse(baseURL)
	if err != nil || (u.Scheme != "http" && u.Scheme != "https") || u.Host == "" {
		return nil, &ConfigError{Msg: fmt.Sprintf("base URL %q is not an absolute http(s) URL", baseURL)}
	}
	return &Client{
		BaseURL:    strings.TrimRight(baseURL, "/"),
		HTTPClient: &http.Client{Timeout: DefaultTimeout},
		token:      token,
	}, nil
}

// String implements fmt.Stringer and deliberately shows no token.
func (c *Client) String() string {
	return fmt.Sprintf("worldtext.Client{BaseURL: %q, Token: %s}", c.BaseURL, redact(c.token))
}

// GoString implements fmt.GoStringer so that %#v is redacted too.
func (c *Client) GoString() string { return c.String() }

// redact returns a log-safe form of a token: never the token itself.
func redact(token string) string {
	if token == "" {
		return "<none>"
	}
	return fmt.Sprintf("<redacted:%d chars>", len(token))
}

// Params are optional request fields. They are passed through to the
// gateway, so fields the spec adds later work without this client needing to
// enumerate them.
//
//	A nil value is never sent, so an omitted optional argument is absent from
//	the request rather than sent as an empty string, which the gateway treats
//	as a value.
type Params map[string]string

// set returns p with key set to value. It does not modify p.
func (p Params) set(key, value string) Params {
	return p.with(Params{key: value})
}

// encode renders params as a form/query string, sorted so requests are
// deterministic. The token never appears here.
func encode(p Params) string {
	vals := make(url.Values, len(p))
	for k, v := range p {
		vals.Set(k, v)
	}
	return vals.Encode()
}

// do performs one request and returns the decoded success envelope. A
// non-2xx status, an envelope "status" other than "0", or an undecodable body
// becomes a typed error.
func (c *Client) do(ctx context.Context, method, path string, params Params) (map[string]any, error) {
	if ctx == nil {
		ctx = context.Background()
	}

	// Reads and deletes take their arguments in the query string; writes send
	// them form-encoded, because the write routes accept PUT and POST bodies.
	var (
		endpoint = c.BaseURL + path
		body     io.Reader
		encoded  = encode(params)
	)
	switch method {
	case http.MethodGet, http.MethodDelete:
		if encoded != "" {
			endpoint += "?" + encoded
		}
	default:
		body = strings.NewReader(encoded)
	}

	if c.Logf != nil {
		c.Logf("%s %s (token %s)", method, path, redact(c.token))
	}

	req, err := http.NewRequestWithContext(ctx, method, endpoint, body)
	if err != nil {
		return nil, &APIError{Base: &Error{Msg: "worldtext: building request: " + err.Error()}}
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}

	hc := c.HTTPClient
	if hc == nil {
		hc = http.DefaultClient
	}
	resp, err := hc.Do(req)
	if err != nil {
		// Never surface the URL: a caller's parameters are in it. transportError
		// strips the token too, in case a Doer wrapped it into an error.
		cause := c.transportError(err)
		msg := fmt.Sprintf("worldtext: transport failure contacting %s: %s", c.BaseURL, describe(cause, c.token))
		return nil, &APIError{Base: &Error{Msg: msg, Desc: describe(cause, c.token), Err: cause}}
	}
	defer resp.Body.Close()

	raw, err := io.ReadAll(io.LimitReader(resp.Body, maxResponseBytes))
	if err != nil {
		return nil, &APIError{Base: &Error{
			Msg:        fmt.Sprintf("worldtext: reading response (HTTP %d): %s", resp.StatusCode, describe(err, c.token)),
			HTTPStatus: resp.StatusCode,
			Err:        err,
		}}
	}
	payload, err := decode(resp.StatusCode, resp.Header.Get("WWW-Authenticate"), raw)
	if err != nil {
		// A hostile or chatty gateway could echo the token back in "desc";
		// scrub it before the error reaches a caller's logs.
		scrub(err, c.token)
	}
	return payload, err
}

// transportError resolves the timeout case to context.DeadlineExceeded so a
// caller can keep using errors.Is.
func (c *Client) transportError(err error) error {
	var netErr interface{ Timeout() bool }
	if errors.As(err, &netErr) && netErr.Timeout() && !errors.Is(err, context.DeadlineExceeded) {
		return fmt.Errorf("%w: %v", context.DeadlineExceeded, err)
	}
	return err
}

// describe renders err for a message with every occurrence of token removed.
func describe(err error, token string) string {
	text := err.Error()
	if token != "" {
		text = strings.ReplaceAll(text, token, redact(token))
	}
	return text
}

// decode turns a response into either the success envelope or a typed error.
func decode(status int, wwwAuthenticate string, raw []byte) (map[string]any, error) {
	payload := map[string]any{}
	if len(raw) > 0 {
		if err := json.Unmarshal(raw, &payload); err != nil {
			return nil, &APIError{Base: &Error{
				Msg:        fmt.Sprintf("worldtext: response was not JSON (HTTP %d)", status),
				HTTPStatus: status,
			}}
		}
	}

	if status >= 200 && status < 300 {
		if flag := get(payload, "status"); flag == "" || flag == string(StatusSuccess) {
			return payload, nil
		}
	}

	code := get(payload, "error")
	desc := get(payload, "desc")
	if desc == "" {
		desc = fmt.Sprintf("HTTP %d", status)
	}
	base := &Error{
		Msg:        fmt.Sprintf("%s (error %s, HTTP %d)", desc, code, status),
		HTTPStatus: status,
		Code:       code,
		Desc:       desc,
		Status:     StatusFlag(get(payload, "status")),
	}
	if base.Status == "" {
		base.Status = StatusPermanent
	}
	// 401 and 403 become their own types; "2"/"3" stay visible through
	// Error.Retryable on the returned *APIError.
	return nil, newKindError(base, scopeFromHeader(wwwAuthenticate))
}

// scrub replaces every occurrence of token in a gateway-supplied description
// and message with its redacted form, so an error is always safe to log.
func scrub(err error, token string) {
	if token == "" {
		return
	}
	var base *Error
	if errors.As(err, &base) {
		base.Desc = strings.ReplaceAll(base.Desc, token, redact(token))
		base.Msg = strings.ReplaceAll(base.Msg, token, redact(token))
	}
}

// scopeFromHeader pulls scope="http" out of a WWW-Authenticate header:
//
//	Bearer error="insufficient_scope", scope="http"
func scopeFromHeader(header string) string {
	if header == "" {
		return ""
	}
	for _, part := range strings.Split(header, ",") {
		part = strings.TrimSpace(part)
		if v, ok := strings.CutPrefix(part, "scope="); ok {
			return strings.Trim(v, `"`)
		}
	}
	return ""
}

// ---------------------------------------------------------------- admin API

// Ping is a liveness check. It needs the "http" permission.
func (c *Client) Ping(ctx context.Context) (*PingResult, error) {
	raw, err := c.do(ctx, http.MethodGet, "/admin/ping", nil)
	if err != nil {
		return nil, err
	}
	return newPingResult(raw), nil
}

// GetCredits returns the account's credit balance. It needs "http".
func (c *Client) GetCredits(ctx context.Context) (*Credits, error) {
	raw, err := c.do(ctx, http.MethodGet, "/admin/credits", nil)
	if err != nil {
		return nil, err
	}
	return newCredits(raw), nil
}

// GetHost returns the name of the host that served the request. It needs
// "http".
func (c *Client) GetHost(ctx context.Context) (*HostName, error) {
	raw, err := c.do(ctx, http.MethodGet, "/admin/host", nil)
	if err != nil {
		return nil, err
	}
	return newHostName(raw), nil
}

// ------------------------------------------------------------------ SMS API

// GetSmsCost returns the price of one message without sending it. It needs
// "http". An empty message prices a default-length message.
func (c *Client) GetSmsCost(ctx context.Context, dstAddr, message string) (*Cost, error) {
	raw, err := c.do(ctx, http.MethodGet, "/sms/cost", Params{"dstaddr": dstAddr, "message": message})
	if err != nil {
		return nil, err
	}
	return newCost(raw), nil
}

// SendSms sends one message to one destination. It needs "http". Extra fields
// such as "sendid", "schedule", "sender" and "udh" go in opts.
func (c *Client) SendSms(ctx context.Context, dstAddr, message string, opts Params) (*SendResult, error) {
	raw, err := c.do(ctx, http.MethodPut, "/sms/send",
		Params{"dstaddr": dstAddr, "message": message}.with(opts))
	if err != nil {
		return nil, err
	}
	return newSendResult(raw), nil
}

// QuerySmsStatus returns the delivery state of a sent message. It needs
// "http".
func (c *Client) QuerySmsStatus(ctx context.Context, msgID string) (*SmsStatus, error) {
	raw, err := c.do(ctx, http.MethodGet, "/sms/query", Params{"msgid": msgID})
	if err != nil {
		return nil, err
	}
	return newSmsStatus(raw), nil
}

// ------------------------------------------------------------------ HLR API

// LookupHLR performs an HLR lookup. It needs "http" and costs a credit.
func (c *Client) LookupHLR(ctx context.Context, msisdn string) (*HLRResult, error) {
	raw, err := c.do(ctx, http.MethodGet, "/hlr/lookup", Params{"msisdn": msisdn})
	if err != nil {
		return nil, err
	}
	return newHLRResult(raw), nil
}

// LookupMNP returns the mobile number portability status. It needs "http".
func (c *Client) LookupMNP(ctx context.Context, msisdn string) (*MNPResult, error) {
	raw, err := c.do(ctx, http.MethodGet, "/hlr/mnp", Params{"msisdn": msisdn})
	if err != nil {
		return nil, err
	}
	return newMNPResult(raw), nil
}

// GetNumberInfo returns the country and network for a number. It needs
// "http".
func (c *Client) GetNumberInfo(ctx context.Context, msisdn string) (*NumberInfo, error) {
	raw, err := c.do(ctx, http.MethodGet, "/hlr/info", Params{"msisdn": msisdn})
	if err != nil {
		return nil, err
	}
	return newNumberInfo(raw), nil
}

// --------------------------------------------------------------- group API

// CreateGroup creates a group. It needs "http".
func (c *Client) CreateGroup(ctx context.Context, name string) (*CreateGroupResult, error) {
	raw, err := c.do(ctx, http.MethodPut, "/group/create", Params{"name": name})
	if err != nil {
		return nil, err
	}
	return newCreateGroupResult(raw), nil
}

// DeleteGroup deletes a group and its contents. It needs "http".
func (c *Client) DeleteGroup(ctx context.Context, groupID string) (*DeleteResult, error) {
	raw, err := c.do(ctx, http.MethodDelete, "/group/destroy", Params{"grpid": groupID})
	if err != nil {
		return nil, err
	}
	return newDeleteResult(raw), nil
}

// AddGroupEntry adds one number to a group. It needs "http". Extra fields
// go in opts.
func (c *Client) AddGroupEntry(ctx context.Context, groupID, number string, opts Params) (*AddEntriesResult, error) {
	raw, err := c.do(ctx, http.MethodPut, "/group/entry",
		Params{"grpid": groupID, "number": number}.with(opts))
	if err != nil {
		return nil, err
	}
	return newAddEntriesResult(raw), nil
}

// DeleteGroupEntry removes one number from a group. It needs "http".
func (c *Client) DeleteGroupEntry(ctx context.Context, groupID, number string) (*DeleteResult, error) {
	raw, err := c.do(ctx, http.MethodDelete, "/group/entry",
		Params{"grpid": groupID, "number": number})
	if err != nil {
		return nil, err
	}
	return newDeleteResult(raw), nil
}

// AddGroupEntries adds several numbers in one call, joined one per line as the
// API expects. It needs "http".
func (c *Client) AddGroupEntries(ctx context.Context, groupID string, numbers []string) (*AddEntriesResult, error) {
	raw, err := c.do(ctx, http.MethodPut, "/group/entries",
		Params{"grpid": groupID, "numbers": strings.Join(numbers, "\n")})
	if err != nil {
		return nil, err
	}
	return newAddEntriesResult(raw), nil
}

// GetGroupNumbers lists the numbers in a group. It needs "http".
func (c *Client) GetGroupNumbers(ctx context.Context, groupID string) (*GroupNumbers, error) {
	raw, err := c.do(ctx, http.MethodGet, "/group/numbers", Params{"grpid": groupID})
	if err != nil {
		return nil, err
	}
	return newGroupNumbers(raw), nil
}

// GetGroupDetails lists a group's numbers with their status. It needs
// "http".
func (c *Client) GetGroupDetails(ctx context.Context, groupID string) (*GroupDetails, error) {
	raw, err := c.do(ctx, http.MethodGet, "/group/details", Params{"grpid": groupID})
	if err != nil {
		return nil, err
	}
	return newGroupDetails(raw), nil
}

// ListGroups returns every group on the account in GroupNumbers.Groups. It
// needs "http".
func (c *Client) ListGroups(ctx context.Context) (*GroupNumbers, error) {
	raw, err := c.do(ctx, http.MethodGet, "/group/list", nil)
	if err != nil {
		return nil, err
	}
	return newGroupList(raw), nil
}

// DeleteGroupContents removes every number from a group, keeping the group.
// It needs "http".
func (c *Client) DeleteGroupContents(ctx context.Context, groupID string) (*DeleteResult, error) {
	raw, err := c.do(ctx, http.MethodDelete, "/group/contents", Params{"grpid": groupID})
	if err != nil {
		return nil, err
	}
	return newDeleteResult(raw), nil
}

// GetGroupCost returns the price of sending to a whole group. It needs "http",
// not "http" - that is the gateway's behaviour, not a documentation slip.
func (c *Client) GetGroupCost(ctx context.Context, groupID, message string) (*GroupCost, error) {
	raw, err := c.do(ctx, http.MethodGet, "/group/cost", Params{"grpid": groupID, "message": message})
	if err != nil {
		return nil, err
	}
	return newGroupCost(raw), nil
}

// SendGroupSms sends a message to every number in a group. It needs "http",
// not "http".
func (c *Client) SendGroupSms(ctx context.Context, groupID, message string, opts Params) (*SendResult, error) {
	raw, err := c.do(ctx, http.MethodPut, "/group/send",
		Params{"grpid": groupID, "message": message}.with(opts))
	if err != nil {
		return nil, err
	}
	return newSendResult(raw), nil
}

// with returns p overlaid with extra; extra keys win.
func (p Params) with(extra Params) Params {
	if len(extra) == 0 {
		return p
	}
	out := make(Params, len(p)+len(extra))
	for k, v := range p {
		out[k] = v
	}
	for k, v := range extra {
		out[k] = v
	}
	return out
}

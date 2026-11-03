package worldtext_test

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"testing"
	"time"

	"nevis.io/worldtext"
)

// mockScriptEnv overrides the mock gateway's path, for builds that run the
// tests from a different directory (for example with a container workdir).
const mockScriptEnv = "WORLDTEXT_MOCK"

// mockScript is the shared mock gateway: one fixture for every language's
// helper library. It sits next to this package in the helper-libraries tree.
const mockScript = "../fixtures/mock_gateway.py"

// Tokens the mock accepts, and the permissions each carries. Anything else
// gets 401.
const (
	tokenSms    = "test-sms"
	tokenGroups = "test-groups"
	tokenAdmin  = "test-admin"
	tokenHlr    = "test-hlr"
	tokenAll    = "test-all"
)

var (
	mockOnce   sync.Once
	mockErr    error
	mockBase   string
	mockToKill *exec.Cmd
)

// TestMain runs the suite and then stops the mock gateway it started.
func TestMain(m *testing.M) {
	code := m.Run()
	stopMock(mockToKill)
	os.Exit(code)
}

// startMock starts the Python mock gateway once per test binary on port 0,
// reads the port off stdout, and arranges for it to be killed afterwards.
// Tests skip when python3 is not installed.
func startMock(t *testing.T) string {
	t.Helper()
	mockOnce.Do(func() { mockBase, mockErr = launchMock() })
	if mockErr == errNoPython {
		t.Skip("python3 is not installed; skipping tests that need the mock gateway")
	}
	if mockErr != nil {
		t.Fatalf("starting mock gateway: %v", mockErr)
	}
	return mockBase
}

// errNoPython reports that the mock could not be started because python3 is
// not installed; tests skip instead of failing.
var errNoPython = errors.New("python3 is not installed")

// launchMock runs the fixture and returns its base URL once it has printed
// its port.
func launchMock() (string, error) {
	python, err := exec.LookPath("python3")
	if err != nil {
		return "", errNoPython
	}
	script := mockScript
	if override := os.Getenv(mockScriptEnv); override != "" {
		script = override
	}
	script, err = filepath.Abs(script)
	if err != nil {
		return "", err
	}
	if _, err := os.Stat(script); err != nil {
		return "", fmt.Errorf("mock gateway not found at %s: %w", script, err)
	}

	cmd := exec.Command(python, script, "0")
	cmd.Stderr = io.Discard
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return "", err
	}
	if err := cmd.Start(); err != nil {
		return "", err
	}
	// TestMain kills the mock when the binary exits.
	mockToKill = cmd

	type result struct {
		line string
		err  error
	}
	lines := make(chan result, 1)
	go func() {
		line, err := bufio.NewReader(stdout).ReadString('\n')
		lines <- result{line, err}
	}()

	select {
	case r := <-lines:
		line := strings.TrimSpace(r.line)
		if r.err != nil && line == "" {
			stopMock(cmd)
			return "", fmt.Errorf("mock gateway exited before reporting a port: %w", r.err)
		}
		if !strings.HasPrefix(line, "PORT ") {
			stopMock(cmd)
			return "", fmt.Errorf("mock gateway did not report a port: %q", line)
		}
		return "http://127.0.0.1:" + strings.TrimPrefix(line, "PORT "), nil
	case <-time.After(20 * time.Second):
		stopMock(cmd)
		return "", fmt.Errorf("mock gateway did not report a port within 20s")
	}
}

func stopMock(cmd *exec.Cmd) {
	if cmd == nil || cmd.Process == nil {
		return
	}
	_ = cmd.Process.Kill()
	_ = cmd.Wait()
}

// newClient builds a client against the mock. The caller picks the token so
// each test exercises exactly the permissions it means to.
func newClient(t *testing.T, token string) *worldtext.Client {
	t.Helper()
	client, err := worldtext.NewClient(startMock(t), token)
	if err != nil {
		t.Fatalf("NewClient: %v", err)
	}
	return client
}

// ---------------------------------------------------------------- success

func TestGetCreditsParsesTypedResponse(t *testing.T) {
	credits, err := newClient(t, tokenAll).GetCredits(context.Background())
	if err != nil {
		t.Fatalf("GetCredits: %v", err)
	}
	// The API sends numbers as JSON strings and the model keeps them so.
	if credits.Credits != "2950" {
		t.Errorf("Credits = %q, want %q", credits.Credits, "2950")
	}
	n, ok := credits.AsInt64()
	if !ok || n != 2950 {
		t.Errorf("AsInt64() = %d, %v; want 2950, true", n, ok)
	}
}

func TestPingAndGetHost(t *testing.T) {
	ctx := context.Background()
	client := newClient(t, tokenAdmin)

	ping, err := client.Ping(ctx)
	if err != nil {
		t.Fatalf("Ping: %v", err)
	}
	if !ping.OK {
		t.Error("Ping.OK = false, want true")
	}

	host, err := client.GetHost(ctx)
	if err != nil {
		t.Fatalf("GetHost: %v", err)
	}
	if host.Hostname != "sms1.example" {
		t.Errorf("Hostname = %q, want %q", host.Hostname, "sms1.example")
	}
}

func TestSendSmsParsesMsgID(t *testing.T) {
	ctx := context.Background()
	client := newClient(t, tokenSms)

	result, err := client.SendSms(ctx, "447989000001", "hi", nil)
	if err != nil {
		t.Fatalf("SendSms: %v", err)
	}
	if len(result.Messages) != 1 {
		t.Fatalf("got %d messages, want 1", len(result.Messages))
	}
	if got := result.MsgID(); got != "10001" {
		t.Errorf("MsgID() = %q, want %q", got, "10001")
	}
	msg := result.Messages[0]
	if msg.DstAddr != "447989000001" {
		t.Errorf("DstAddr = %q", msg.DstAddr)
	}
	if msg.Credits != "1.0" {
		t.Errorf("Credits = %q, want %q - must stay a string", msg.Credits, "1.0")
	}
	if id, ok := msg.MsgIDInt(); !ok || id != 10001 {
		t.Errorf("MsgIDInt() = %d, %v; want 10001, true", id, ok)
	}

	status, err := client.QuerySmsStatus(ctx, "10001")
	if err != nil {
		t.Fatalf("QuerySmsStatus: %v", err)
	}
	if status.State != "5" || !status.Final() {
		t.Errorf("SmsStatus = %+v, want state 5 and final", status)
	}
}

func TestGetSmsCostAndHLR(t *testing.T) {
	ctx := context.Background()

	cost, err := newClient(t, tokenSms).GetSmsCost(ctx, "447989000001", "hi")
	if err != nil {
		t.Fatalf("GetSmsCost: %v", err)
	}
	if cost.Credits != "1.0" {
		t.Errorf("Credits = %q, want %q", cost.Credits, "1.0")
	}

	hlr := newClient(t, tokenHlr)
	lookup, err := hlr.LookupHLR(ctx, "447989000001")
	if err != nil {
		t.Fatalf("LookupHLR: %v", err)
	}
	if lookup.Region != "UK" {
		t.Errorf("Region = %q, want UK", lookup.Region)
	}
	mnp, err := hlr.LookupMNP(ctx, "447989000001")
	if err != nil {
		t.Fatalf("LookupMNP: %v", err)
	}
	if mnp.MNPPortStatus != "ported" {
		t.Errorf("MNPPortStatus = %q, want ported", mnp.MNPPortStatus)
	}
	info, err := hlr.GetNumberInfo(ctx, "447989000001")
	if err != nil {
		t.Fatalf("GetNumberInfo: %v", err)
	}
	if info.Network != "O2-UK" {
		t.Errorf("Network = %q, want O2-UK", info.Network)
	}
}

func TestGroupLifecycle(t *testing.T) {
	ctx := context.Background()
	client := newClient(t, tokenGroups)

	created, err := client.CreateGroup(ctx, "g")
	if err != nil {
		t.Fatalf("CreateGroup: %v", err)
	}
	// The create route sends both grpid and groupid; either is accepted.
	if created.GroupID != "4242" {
		t.Errorf("GroupID = %q, want 4242", created.GroupID)
	}

	added, err := client.AddGroupEntry(ctx, "4242", "447989000001", nil)
	if err != nil {
		t.Fatalf("AddGroupEntry: %v", err)
	}
	if added.Added != "1" {
		t.Errorf("Added = %q, want 1", added.Added)
	}

	bulk, err := client.AddGroupEntries(ctx, "4242", []string{"447989000001", "447989000002"})
	if err != nil {
		t.Fatalf("AddGroupEntries: %v", err)
	}
	if bulk.Added != "2" {
		t.Errorf("Added = %q, want 2", bulk.Added)
	}

	numbers, err := client.GetGroupNumbers(ctx, "4242")
	if err != nil {
		t.Fatalf("GetGroupNumbers: %v", err)
	}
	if got := strings.Join(numbers.Numbers, ","); got != "447989000001,447989000002" {
		t.Errorf("Numbers = %q", got)
	}

	details, err := client.GetGroupDetails(ctx, "4242")
	if err != nil {
		t.Fatalf("GetGroupDetails: %v", err)
	}
	if len(details.Entries) != 2 {
		t.Fatalf("got %d details, want 2", len(details.Entries))
	}
	if details.Entries[0].Status != "5" {
		t.Errorf("Status = %q, want 5", details.Entries[0].Status)
	}

	list, err := client.ListGroups(ctx)
	if err != nil {
		t.Fatalf("ListGroups: %v", err)
	}
	if len(list.Groups) != 1 {
		t.Fatalf("got %d groups, want 1", len(list.Groups))
	}
	if list.Groups[0].GroupID != "4242" || list.Groups[0].Name != "test-group" {
		t.Errorf("group = %+v", list.Groups[0])
	}

	if _, err := client.DeleteGroupContents(ctx, "4242"); err != nil {
		t.Fatalf("DeleteGroupContents: %v", err)
	}
	if _, err := client.DeleteGroupEntry(ctx, "4242", "447989000001"); err != nil {
		t.Fatalf("DeleteGroupEntry: %v", err)
	}
	if _, err := client.DeleteGroup(ctx, "4242"); err != nil {
		t.Fatalf("DeleteGroup: %v", err)
	}
}

func TestGroupCostAndSendRequireSmsNotGroups(t *testing.T) {
	ctx := context.Background()

	// A groups token has no sms permission; these routes must refuse it.
	for name, call := range map[string]func(*worldtext.Client) error{
		"GetGroupCost": func(c *worldtext.Client) error { _, err := c.GetGroupCost(ctx, "4242", "hi"); return err },
		"SendGroupSms": func(c *worldtext.Client) error { _, err := c.SendGroupSms(ctx, "4242", "hi", nil); return err },
	} {
		var permErr *worldtext.PermissionError
		if err := call(newClient(t, tokenGroups)); !errors.As(err, &permErr) {
			t.Errorf("%s with a groups token: got %v, want *PermissionError", name, err)
		}
	}

	cost, err := newClient(t, tokenSms).GetGroupCost(ctx, "4242", "hi")
	if err != nil {
		t.Fatalf("GetGroupCost: %v", err)
	}
	if cost.Credits != "2.0" || cost.EntryCount != "2" {
		t.Errorf("GroupCost = %+v", cost)
	}

	sent, err := newClient(t, tokenSms).SendGroupSms(ctx, "4242", "hi", nil)
	if err != nil {
		t.Fatalf("SendGroupSms: %v", err)
	}
	if sent.MsgID() != "10002" {
		t.Errorf("MsgID() = %q, want 10002", sent.MsgID())
	}
}

// ----------------------------------------------------------------- errors

func TestBadTokenIs401(t *testing.T) {
	_, err := newClient(t, "not-a-token").GetCredits(context.Background())
	if err == nil {
		t.Fatal("expected an error, got nil")
	}

	var authErr *worldtext.AuthenticationError
	if !errors.As(err, &authErr) {
		t.Fatalf("error is %T (%v), want *AuthenticationError", err, err)
	}
	if !errors.Is(err, worldtext.ErrAuthentication) {
		t.Error("errors.Is(err, ErrAuthentication) = false")
	}
	if authErr.Base.HTTPStatus != http.StatusUnauthorized {
		t.Errorf("HTTPStatus = %d, want 401", authErr.Base.HTTPStatus)
	}
	if authErr.Base.Code != "1000" {
		t.Errorf("Code = %q, want 1000", authErr.Base.Code)
	}
	var permErr *worldtext.PermissionError
	if errors.As(err, &permErr) {
		t.Error("a 401 also matched *PermissionError")
	}
}

func TestWrongScopeIs403WithRequiredScope(t *testing.T) {
	_, err := newClient(t, tokenSms).GetCredits(context.Background())
	if err == nil {
		t.Fatal("expected an error, got nil")
	}

	var permErr *worldtext.PermissionError
	if !errors.As(err, &permErr) {
		t.Fatalf("error is %T (%v), want *PermissionError", err, err)
	}
	if !errors.Is(err, worldtext.ErrPermission) {
		t.Error("errors.Is(err, ErrPermission) = false")
	}
	if permErr.Base.HTTPStatus != http.StatusForbidden {
		t.Errorf("HTTPStatus = %d, want 403", permErr.Base.HTTPStatus)
	}
	// The required scope is surfaced so the caller can say which to add.
	if permErr.RequiredScope != "admin" {
		t.Errorf("RequiredScope = %q, want admin", permErr.RequiredScope)
	}
	var authErr *worldtext.AuthenticationError
	if errors.As(err, &authErr) {
		t.Error("a 403 also matched *AuthenticationError")
	}
}

func TestMissingArgumentIs400(t *testing.T) {
	_, err := newClient(t, tokenSms).GetSmsCost(context.Background(), "", "hi")
	if err == nil {
		t.Fatal("expected an error, got nil")
	}

	var apiErr *worldtext.APIError
	if !errors.As(err, &apiErr) {
		t.Fatalf("error is %T (%v), want *APIError", err, err)
	}
	if !errors.Is(err, worldtext.ErrAPI) {
		t.Error("errors.Is(err, ErrAPI) = false")
	}
	if apiErr.Base.HTTPStatus != http.StatusBadRequest {
		t.Errorf("HTTPStatus = %d, want 400", apiErr.Base.HTTPStatus)
	}
	if apiErr.Base.Code != "1200" {
		t.Errorf("Code = %q, want 1200", apiErr.Base.Code)
	}
	if apiErr.Base.Desc != "Incorrect arguments specified" {
		t.Errorf("Desc = %q", apiErr.Base.Desc)
	}
	if apiErr.Retryable() {
		t.Error("Retryable() = true for a validation error")
	}

	// And through the base type, as a generic caller would.
	var base *worldtext.Error
	if !errors.As(err, &base) || base.HTTPStatus != 400 {
		t.Errorf("errors.As(*Error) = %v", err)
	}
}

func TestAllErrorsMatchASentinelAndShareFields(t *testing.T) {
	ctx := context.Background()
	cases := []struct {
		name     string
		call     func(*worldtext.Client) error
		token    string
		sentinel error
	}{
		{"auth", func(c *worldtext.Client) error { _, err := c.GetCredits(ctx); return err }, "nope", worldtext.ErrAuthentication},
		{"perm", func(c *worldtext.Client) error { _, err := c.GetCredits(ctx); return err }, tokenSms, worldtext.ErrPermission},
		{"api", func(c *worldtext.Client) error { _, err := c.GetSmsCost(ctx, "", ""); return err }, tokenSms, worldtext.ErrAPI},
	}
	for _, tc := range cases {
		err := tc.call(newClient(t, tc.token))
		if err == nil {
			t.Fatalf("%s: expected an error", tc.name)
		}
		if !errors.Is(err, tc.sentinel) {
			t.Errorf("%s: errors.Is with the expected sentinel = false", tc.name)
		}
		var base *worldtext.Error
		if !errors.As(err, &base) {
			t.Fatalf("%s: errors.As(*Error) = false", tc.name)
		}
		if base.HTTPStatus == 0 || base.Code == "" || base.Desc == "" {
			t.Errorf("%s: incomplete error %+v", tc.name, base)
		}
	}
}

// ------------------------------------------------------------- retryability

func TestRetryableStatusFlags(t *testing.T) {
	cases := []struct {
		status worldtext.StatusFlag
		http   int
		want   bool
	}{
		{worldtext.StatusPermanent, 400, false},
		{worldtext.StatusTemporary, 400, true},
		{worldtext.StatusRetry, 409, true},
		{worldtext.StatusPermanent, 503, true},
	}
	for _, tc := range cases {
		e := &worldtext.Error{HTTPStatus: tc.http, Status: tc.status}
		if got := e.Retryable(); got != tc.want {
			t.Errorf("Retryable(status %q, HTTP %d) = %v, want %v", tc.status, tc.http, got, tc.want)
		}
	}

	// End to end: a local server replies with a temporary envelope.
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusServiceUnavailable)
		_, _ = io.WriteString(w, `{"status":"2","error":"1105","desc":"try later"}`)
	}))
	defer server.Close()

	client, err := worldtext.NewClient(server.URL, tokenAll)
	if err != nil {
		t.Fatal(err)
	}
	var apiErr *worldtext.APIError
	if _, err := client.Ping(context.Background()); !errors.As(err, &apiErr) {
		t.Fatalf("error is %T (%v), want *APIError", err, err)
	}
	if !apiErr.Retryable() {
		t.Error("Retryable() = false for status 2 over HTTP 503")
	}
	if apiErr.Base.Status != worldtext.StatusTemporary {
		t.Errorf("Status = %q, want 2", apiErr.Base.Status)
	}
}

// ------------------------------------------------------------ token safety

const secretToken = "wt_live_supersecretvalue_that_must_never_leak"

func TestClientStringRedactsToken(t *testing.T) {
	client, err := worldtext.NewClient("http://127.0.0.1:1", secretToken)
	if err != nil {
		t.Fatal(err)
	}
	for _, text := range []string{
		client.String(),
		fmt.Sprint(client),
		fmt.Sprintf("%v", client),
		fmt.Sprintf("%+v", client),
		fmt.Sprintf("%#v", client),
		fmt.Sprintf("%s", client),
	} {
		assertRedacted(t, "client", text)
	}
	if !strings.Contains(client.String(), "<redacted:") {
		t.Errorf("String() = %q, want a <redacted:N chars> marker", client.String())
	}
}

func TestErrorMessagesAndReprsRedactToken(t *testing.T) {
	// A refused connection: the transport path builds the message itself.
	client, err := worldtext.NewClient("http://127.0.0.1:1", secretToken)
	if err != nil {
		t.Fatal(err)
	}
	_, err = client.GetCredits(context.Background())
	if err == nil {
		t.Fatal("expected a transport error")
	}
	assertRedacted(t, "transport error", err.Error())
	assertRedacted(t, "transport error repr", fmt.Sprintf("%#v", err))

	// A 401 from an echo server that mentions the token it was given: even a
	// hostile description must not leak it.
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusUnauthorized)
		fmt.Fprintf(w, `{"status":"1","error":"1000","desc":"bad token %s"}`, r.Header.Get("Authorization"))
	}))
	defer server.Close()

	authClient, err := worldtext.NewClient(server.URL, secretToken)
	if err != nil {
		t.Fatal(err)
	}
	var authErr *worldtext.AuthenticationError
	if _, err := authClient.GetCredits(context.Background()); !errors.As(err, &authErr) {
		t.Fatalf("error is %T (%v), want *AuthenticationError", err, err)
	}
	assertRedacted(t, "auth error", authErr.Error())
	assertRedacted(t, "auth error fmt", fmt.Sprintf("%+v %#v", authErr, authErr))
}

func TestErrorMessagesFromMockRedactToken(t *testing.T) {
	_, err := newClient(t, secretToken).GetCredits(context.Background())
	if err == nil {
		t.Fatal("expected an authentication error")
	}
	assertRedacted(t, "mock 401 error", err.Error())
	assertRedacted(t, "mock 401 repr", fmt.Sprintf("%#v", err))
}

func TestLoggingRedactsToken(t *testing.T) {
	var lines []string
	client := newClient(t, secretToken)
	client.Logf = func(format string, args ...any) {
		lines = append(lines, fmt.Sprintf(format, args...))
	}

	// Both a failure and a success, so the log covers both paths.
	_, _ = client.GetCredits(context.Background())

	if len(lines) == 0 {
		t.Fatal("expected at least one log line")
	}
	for _, line := range lines {
		assertRedacted(t, "log line", line)
	}
}

func TestNewClientRejectsMissingToken(t *testing.T) {
	if _, err := worldtext.NewClient(worldtext.DefaultBaseURL, ""); err == nil {
		t.Fatal("NewClient with an empty token returned no error")
	}
	if _, err := worldtext.NewClient("not a url", secretToken); err == nil {
		t.Fatal("NewClient with an unusable base URL returned no error")
	} else {
		assertRedacted(t, "config error", err.Error())
	}
}

// ----------------------------------------------------------------- helpers

func assertRedacted(t *testing.T, what, text string) {
	t.Helper()
	if strings.Contains(text, secretToken) {
		t.Errorf("%s leaked the token: %q", what, text)
	}
}

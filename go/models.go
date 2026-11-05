package worldtext

import "strconv"

// The gateway returns numbers as JSON *strings*: "credits":"2950",
// "msgid":"10001", "status":"0". That is deliberate and documented, so the
// models keep them as strings to round-trip faithfully and expose int64
// accessors where a value is genuinely arithmetic. Parsing on the way in would
// quietly lose precision on long message ids and misrepresent credit balances.
//
// Each model keeps the decoded envelope in Raw so that a field this library
// does not model yet is still reachable.
//
// The decoding helpers below are deliberately lenient - a field the gateway
// sends as a JSON number instead of a string is still read as text, so a
// future protocol tweak cannot make parsing fail.

// stringValue reads a JSON value as a string. Numbers keep their literal
// spelling; null and absent both yield "".
func stringValue(v any) string {
	switch x := v.(type) {
	case nil:
		return ""
	case string:
		return x
	case bool:
		return strconv.FormatBool(x)
	case float64:
		return strconv.FormatFloat(x, 'f', -1, 64)
	default:
		return ""
	}
}

// get returns the first of names present and non-null in m, else "".
//
// The API is inconsistent about naming: group ids arrive as either "grpid" or
// "groupid" depending on the route, so every model that reads one accepts
// both.
func get(m map[string]any, names ...string) string {
	for _, n := range names {
		if v, ok := m[n]; ok && v != nil {
			return stringValue(v)
		}
	}
	return ""
}

// getList returns m[name] as a list. A single object is treated as a
// one-element list, because the API does that for single results.
func getList(m map[string]any, name string) []any {
	switch v := m[name].(type) {
	case []any:
		return v
	case map[string]any:
		return []any{v}
	default:
		return nil
	}
}

// parseInt64 converts an API string to a number, reporting false when the
// value is absent or not an integer.
func parseInt64(s string) (int64, bool) {
	if s == "" {
		return 0, false
	}
	n, err := strconv.ParseInt(s, 10, 64)
	if err != nil {
		return 0, false
	}
	return n, true
}

// Credits returns AsInt64 and reports whether the reading succeeded.
func (c Credits) AsInt64() (int64, bool) { return parseInt64(c.Credits) }

// PingResult is the answer to Ping.
type PingResult struct {
	// OK is true when the gateway reported status "0".
	OK  bool
	Raw map[string]any
}

// Credits is the account's credit balance. Credits is a string, as sent.
type Credits struct {
	Credits string
	Raw     map[string]any
}

// AccountDetails names the server that answered.
type AccountDetails struct {
	Hostname string
	Raw      map[string]any
}

// Cost is the price of a single message.
type Cost struct {
	Credits string
	Raw     map[string]any
}

// SentMessage is one accepted message within a SendResult.
type SentMessage struct {
	MsgID    string
	DstAddr  string
	Credits  string
	Status   string
	SendTime string
	Raw      map[string]any
}

// MsgIDInt returns MsgID as a number and reports whether the reading
// succeeded.
func (s SentMessage) MsgIDInt() (int64, bool) { return parseInt64(s.MsgID) }

// SendResult is a send response: one SentMessage per destination, in Result
// order, even when the gateway sent a single object instead of a list.
type SendResult struct {
	Messages []SentMessage
	Raw      map[string]any
}

// MsgID is the first message id, or "" when nothing was accepted. That is the
// common single-recipient case.
func (r SendResult) MsgID() string {
	if len(r.Messages) == 0 {
		return ""
	}
	return r.Messages[0].MsgID
}

// SmsStatus is the delivery state of a sent message.
type SmsStatus struct {
	State     string
	Error     string
	FinalDate string
	Raw       map[string]any
}

// Final reports whether the message has finished its journey. It is false
// while the message is still in flight.
func (s SmsStatus) Final() bool { return s.FinalDate != "" }

// HLRResult is the answer to LookupHLR.
type HLRResult struct {
	Docomo string
	Region string
	Info   string
	Error  string
	Raw    map[string]any
}

// MNPResult is the portability answer for a number.
type MNPResult struct {
	MNPPortStatus string
	MNPPortDate   string
	Raw           map[string]any
}

// NumberInfo is static information about a number's network.
type NumberInfo struct {
	CC      string
	MCC     string
	MNC     string
	Country string
	Network string
	Credits string
	Raw     map[string]any
}

// Group is one entry in the group list.
type Group struct {
	// GroupID comes from "grpid", "groupid" or "id", whichever the route sent.
	GroupID string
	Name    string
	Size    string
	Raw     map[string]any
}

// SizeInt returns Size as a number and reports whether the reading succeeded.
func (g Group) SizeInt() (int64, bool) { return parseInt64(g.Size) }

// CreateGroupResult carries the id of a new group.
type CreateGroupResult struct {
	GroupID string
	Raw     map[string]any
}

// GroupCost is the price of sending to a whole group.
type GroupCost struct {
	Credits    string
	EntryCount string
	Raw        map[string]any
}

// EntryCountInt returns EntryCount as a number and reports whether the
// reading succeeded.
func (c GroupCost) EntryCountInt() (int64, bool) { return parseInt64(c.EntryCount) }

// GroupNumbers lists the numbers in a group. ListGroups returns the same type
// with Groups populated.
type GroupNumbers struct {
	Numbers []string
	Groups  []Group
	Raw     map[string]any
}

// GroupEntry is one number in a group with its delivery state.
type GroupEntry struct {
	Number string
	Status string
}

// GroupDetails lists a group's entries with per-number status.
type GroupDetails struct {
	Entries []GroupEntry
	Raw     map[string]any
}

// AddEntriesResult reports how many numbers a bulk add accepted.
type AddEntriesResult struct {
	Added  string
	Failed string
	Raw    map[string]any
}

// AddedInt returns Added as a number and reports whether the reading
// succeeded.
func (r AddEntriesResult) AddedInt() (int64, bool) { return parseInt64(r.Added) }

// DeleteResult reports what a deletion removed. Deleted is empty when the
// gateway only acknowledged the call.
type DeleteResult struct {
	Deleted string
	Raw     map[string]any
}

// DeletedInt returns Deleted as a number and reports whether the reading
// succeeded.
func (r DeleteResult) DeletedInt() (int64, bool) { return parseInt64(r.Deleted) }

// Constructors. Each tolerates absent optional fields.

func newPingResult(raw map[string]any) *PingResult {
	return &PingResult{OK: get(raw, "status") == string(StatusSuccess), Raw: raw}
}

func newCredits(raw map[string]any) *Credits {
	return &Credits{Credits: get(raw, "credits"), Raw: raw}
}

func newAccountDetails(raw map[string]any) *AccountDetails {
	return &AccountDetails{Hostname: get(raw, "accountid"), Raw: raw}
}

func newCost(raw map[string]any) *Cost {
	return &Cost{Credits: get(raw, "credits"), Raw: raw}
}

func newSentMessage(raw map[string]any) SentMessage {
	return SentMessage{
		MsgID:    get(raw, "msgid"),
		DstAddr:  get(raw, "dstaddr"),
		Credits:  get(raw, "credits"),
		Status:   get(raw, "status"),
		SendTime: get(raw, "sendtime"),
		Raw:      raw,
	}
}

func newSendResult(raw map[string]any) *SendResult {
	items := getList(raw, "result")
	messages := make([]SentMessage, 0, len(items))
	for _, item := range items {
		m := map[string]any{}
		if obj, ok := item.(map[string]any); ok {
			m = obj
		}
		messages = append(messages, newSentMessage(m))
	}
	return &SendResult{Messages: messages, Raw: raw}
}

func newSmsStatus(raw map[string]any) *SmsStatus {
	return &SmsStatus{
		State:     get(raw, "state"),
		Error:     get(raw, "error"),
		FinalDate: get(raw, "finaldate"),
		Raw:       raw,
	}
}

func newHLRResult(raw map[string]any) *HLRResult {
	return &HLRResult{
		Docomo: get(raw, "docomo"),
		Region: get(raw, "region"),
		Info:   get(raw, "info"),
		Error:  get(raw, "error"),
		Raw:    raw,
	}
}

func newMNPResult(raw map[string]any) *MNPResult {
	return &MNPResult{
		MNPPortStatus: get(raw, "mnpportstatus"),
		MNPPortDate:   get(raw, "mnpportdate"),
		Raw:           raw,
	}
}

func newNumberInfo(raw map[string]any) *NumberInfo {
	return &NumberInfo{
		CC:      get(raw, "cc"),
		MCC:     get(raw, "mcc"),
		MNC:     get(raw, "mnc"),
		Country: get(raw, "country"),
		Network: get(raw, "network"),
		Credits: get(raw, "credits"),
		Raw:     raw,
	}
}

func newGroup(raw map[string]any) Group {
	return Group{
		GroupID: get(raw, "grpid", "groupid", "id"),
		Name:    get(raw, "name"),
		Size:    get(raw, "size", "entries"),
		Raw:     raw,
	}
}

func newCreateGroupResult(raw map[string]any) *CreateGroupResult {
	return &CreateGroupResult{GroupID: get(raw, "grpid", "groupid", "id"), Raw: raw}
}

func newGroupCost(raw map[string]any) *GroupCost {
	return &GroupCost{
		Credits:    get(raw, "credits"),
		EntryCount: get(raw, "entrycount", "entries"),
		Raw:        raw,
	}
}

func newGroupNumbers(raw map[string]any) *GroupNumbers {
	items := getList(raw, "numbers")
	if items == nil {
		items = getList(raw, "entry")
	}
	numbers := make([]string, 0, len(items))
	for _, item := range items {
		if obj, ok := item.(map[string]any); ok {
			numbers = append(numbers, get(obj, "number"))
			continue
		}
		numbers = append(numbers, stringValue(item))
	}
	return &GroupNumbers{Numbers: numbers, Raw: raw}
}

func newGroupDetails(raw map[string]any) *GroupDetails {
	items := getList(raw, "details")
	if items == nil {
		items = getList(raw, "entry")
	}
	entries := make([]GroupEntry, 0, len(items))
	for _, item := range items {
		if obj, ok := item.(map[string]any); ok {
			entries = append(entries, GroupEntry{Number: get(obj, "number"), Status: get(obj, "status")})
			continue
		}
		entries = append(entries, GroupEntry{Number: stringValue(item)})
	}
	return &GroupDetails{Entries: entries, Raw: raw}
}

func newAddEntriesResult(raw map[string]any) *AddEntriesResult {
	return &AddEntriesResult{Added: get(raw, "added"), Failed: get(raw, "failed"), Raw: raw}
}

func newDeleteResult(raw map[string]any) *DeleteResult {
	return &DeleteResult{Deleted: get(raw, "deleted", "rows"), Raw: raw}
}

// newGroupList builds the ListGroups result: the group array arrives under
// "group" (singular) and may be a single object.
func newGroupList(raw map[string]any) *GroupNumbers {
	items := getList(raw, "group")
	groups := make([]Group, 0, len(items))
	for _, item := range items {
		obj, ok := item.(map[string]any)
		if !ok {
			continue
		}
		groups = append(groups, newGroup(obj))
	}
	return &GroupNumbers{Groups: groups, Raw: raw}
}

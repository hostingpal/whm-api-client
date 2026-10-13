package capability

import (
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

func TestProfileSchemaAndStrictDecode(t *testing.T) {
	raw, err := os.ReadFile("../../../../docs/api/examples/capabilities/synthetic-success.json")
	if err != nil {
		t.Fatal(err)
	}
	p, err := DecodeProfile(raw)
	if err != nil {
		t.Fatal(err)
	}
	if p.CaptureStatus != "success" || len(p.AdvertisedNames) != 3 {
		t.Fatalf("wrong decoded profile: %+v", p)
	}
	var object map[string]any
	if err := json.Unmarshal(raw, &object); err != nil {
		t.Fatal(err)
	}
	tests := map[string]func(map[string]any){
		"unknown":          func(m map[string]any) { m["token"] = "do not retain" },
		"wrong case":       func(m map[string]any) { m["Schema_Version"] = m["schema_version"]; delete(m, "schema_version") },
		"failed inventory": func(m map[string]any) { m["capture_status"] = "failed" },
		"empty success":    func(m map[string]any) { m["advertised_names"] = []any{} },
		"invented uapi":    func(m map[string]any) { m["uapi_inventory"] = "available" },
		"wrong function":   func(m map[string]any) { m["evidence"].([]any)[1].(map[string]any)["function"] = "deleteaccount" },
		"raw reason":       func(m map[string]any) { m["evidence"].([]any)[0].(map[string]any)["reason"] = "raw reason" },
		"wrong route": func(m map[string]any) {
			m["evidence"].([]any)[0].(map[string]any)["route"] = "/cpsess123/json-api/version"
		},
		"partial masquerade": func(m map[string]any) { m["evidence"].([]any)[1].(map[string]any)["status"] = "failed" },
	}
	for name, mutate := range tests {
		t.Run(name, func(t *testing.T) {
			var m map[string]any
			json.Unmarshal(raw, &m)
			mutate(m)
			data, _ := json.Marshal(m)
			if _, e := DecodeProfile(data); e == nil {
				t.Fatal("accepted invalid profile")
			}
		})
	}
	if _, e := DecodeProfile(append(raw, []byte(` {}`)...)); e == nil {
		t.Fatal("accepted trailing JSON")
	}
	for _, name := range []string{"synthetic-success.json", "synthetic-partial.json", "synthetic-failed.json"} {
		data, e := os.ReadFile("../../../../docs/api/examples/capabilities/" + name)
		if e != nil {
			t.Fatal(e)
		}
		if _, e := DecodeProfile(data); e != nil {
			t.Fatalf("%s: %v", name, e)
		}
	}
	if err := schema.Validate("capability-profile-v1.schema.json", p); err != nil {
		t.Fatal(err)
	}
	if _, err := DecodeProfile([]byte(strings.ReplaceAll(string(raw), "\"api_result\": 1", "\"api_result\": 1.00000000000000000000000000001"))); err == nil {
		t.Fatal("number precision lost before validation")
	}
}

func TestDecodeProfileRejectsInvalidMetadata(t *testing.T) {
	raw, err := os.ReadFile("../../../../docs/api/examples/capabilities/synthetic-success.json")
	if err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct{ name, key, value string }{
		{"invalid host and port", "origin", "https://invalid host:99999"},
		{"invalid port", "origin", "https://synthetic.example.invalid:99999"},
		{"unnormalized host", "origin", "https://SYNTHETIC.example.invalid:2087"},
		{"invalid timestamp", "captured_at", "2026-99-99T99:99:99Z"},
		{"impossible date", "captured_at", "2026-02-30T12:00:00Z"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			var object map[string]any
			if err := json.Unmarshal(raw, &object); err != nil {
				t.Fatal(err)
			}
			object[tc.key] = tc.value
			invalid, err := json.Marshal(object)
			if err != nil {
				t.Fatal(err)
			}
			profile, err := DecodeProfile(invalid)
			if err == nil {
				t.Fatal("accepted invalid saved metadata")
			}
			if profile.Origin != "" || profile.CaptureStatus != "" {
				t.Fatal("returned invalid profile alongside failure")
			}
			if err.Error() != "capability capture: invalid_profile" {
				t.Fatalf("unsafe error: %v", err)
			}
		})
	}
}
func TestDecodeProfileAllowsFailedCaptureWithoutContext(t *testing.T) {
	raw, err := os.ReadFile("../../../../docs/api/examples/capabilities/synthetic-failed.json")
	if err != nil {
		t.Fatal(err)
	}
	var object map[string]any
	if err := json.Unmarshal(raw, &object); err != nil {
		t.Fatal(err)
	}
	object["origin"] = ""
	object["context"] = map[string]any{"username": "", "cpanel_context": nil}
	object["evidence"] = []any{}
	data, err := json.Marshal(object)
	if err != nil {
		t.Fatal(err)
	}
	profile, err := DecodeProfile(data)
	if err != nil {
		t.Fatal(err)
	}
	if profile.CaptureStatus != "failed" || profile.Origin != "" || profile.Context.Username != "" || profile.Context.CPanelContext != nil {
		t.Fatalf("changed absent failed context: %+v", profile)
	}
}

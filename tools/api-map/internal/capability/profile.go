// Package capability captures the two explicitly allowlisted WHM observations.
package capability

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"time"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
)

// CaptureConfig requires explicit opt-in and HTTPS. Token is never serialized or formatted.
type CaptureConfig struct {
	Origin        string `json:"origin"`
	Username      string `json:"username"`
	CPanelContext string `json:"cpanel_context,omitempty"`
	OptIn         bool   `json:"opt_in"`
	Token         string `json:"-"`
}

func (CaptureConfig) Format(s fmt.State, verb rune) {
	fmt.Fprint(s, "CaptureConfig{credentials:redacted}")
}

type AccountContext struct {
	Username      string  `json:"username"`
	CPanelContext *string `json:"cpanel_context"`
}

// Evidence contains only locally classified outcomes and numeric statuses.
type Evidence struct {
	Function      string `json:"function"`
	Route         string `json:"route"`
	Status        string `json:"status"`
	HTTPStatus    int    `json:"http_status,omitempty"`
	APIResult     *int64 `json:"api_result,omitempty"`
	ErrorCategory string `json:"error_category,omitempty"`
}

// Profile is a sanitized observation, not an assertion of support for docs-only operations.
type Profile struct {
	SchemaVersion   string         `json:"schema_version"`
	Origin          string         `json:"origin"`
	Context         AccountContext `json:"context"`
	CapturedAt      string         `json:"captured_at"`
	ServerVersion   string         `json:"server_version"`
	CaptureStatus   string         `json:"capture_status"`
	AdvertisedNames []string       `json:"advertised_names"`
	Evidence        []Evidence     `json:"evidence"`
	UAPIInventory   string         `json:"uapi_inventory"`
	DirectUAPIAuth  string         `json:"direct_uapi_auth"`
}

// DecodeProfile validates exact JSON keys and numbers before decoding the Go type.
func DecodeProfile(raw []byte) (Profile, error) {
	var profile Profile
	decoder := json.NewDecoder(bytes.NewReader(raw))
	decoder.UseNumber()
	var value any
	if err := decoder.Decode(&value); err != nil {
		return profile, &Failure{Category: "invalid_profile"}
	}
	var trailing any
	if decoder.Decode(&trailing) != io.EOF {
		return profile, &Failure{Category: "invalid_profile"}
	}
	if err := schema.Validate("capability-profile-v1.schema.json", value); err != nil {
		return profile, &Failure{Category: "invalid_profile"}
	}
	if err := json.Unmarshal(raw, &profile); err != nil {
		return Profile{}, &Failure{Category: "invalid_profile"}
	}
	if profile.Origin != "" {
		origin, valid := normalizeOrigin(profile.Origin)
		if !valid || origin != profile.Origin {
			return Profile{}, &Failure{Category: "invalid_profile"}
		}
	}
	if _, err := time.Parse(time.RFC3339, profile.CapturedAt); err != nil {
		return Profile{}, &Failure{Category: "invalid_profile"}
	}
	return profile, nil
}

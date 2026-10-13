package mapbuild

import (
	"fmt"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/model"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/schema"
	"github.com/hostingpal/whm-api-client/tools/api-map/internal/source"
)

func validateAliases(bundle source.Bundle, aliases []model.Alias, ops map[string]*model.Operation, variants map[string]bool) error {
	if err := schema.Validate("annotations-v1.schema.json", model.AnnotationsFile{SchemaVersion: "1.0", Annotations: []model.Annotation{}, Aliases: append([]model.Alias{}, aliases...)}); err != nil {
		return fmt.Errorf("invalid aliases: %w", err)
	}
	links := map[string]string{}
	for _, a := range aliases {
		if _, ok := links[a.AliasID]; ok {
			return fmt.Errorf("ambiguous alias target")
		}
		if ops[a.AliasID] != nil || variants[a.AliasID] {
			return fmt.Errorf("alias shadows canonical identity")
		}
		if err := validateEvidence(bundle, a.Evidence); err != nil {
			return err
		}
		links[a.AliasID] = a.TargetID
	}
	for _, a := range aliases {
		seen := map[string]bool{}
		target := a.AliasID
		for {
			if seen[target] {
				return fmt.Errorf("alias cycle")
			}
			seen[target] = true
			next, ok := links[target]
			if !ok {
				break
			}
			target = next
		}
		if ops[target] == nil && !variants[target] {
			return fmt.Errorf("unknown alias target")
		}
		if strings.Contains(a.AliasID, "#") != strings.Contains(target, "#") {
			return fmt.Errorf("alias and target must have the same identity scope")
		}
	}
	return nil
}

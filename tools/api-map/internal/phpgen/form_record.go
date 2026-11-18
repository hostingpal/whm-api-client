package phpgen

import (
	"fmt"
	"strings"

	"github.com/hostingpal/whm-api-client/tools/api-map/internal/effective"
)

func formRecordShape(record *effective.FormRecord) string {
	var shapes []string
	for _, variant := range record.Variants {
		tag := "type"
		if record.Untagged {
			tag += "?"
		}
		lines := []string{"array{", "    " + tag + ": " + phpString(variant.Tag) + ","}
		for _, field := range variant.Fields {
			name := field.Name
			if !field.Required {
				name += "?"
			}
			lines = append(lines, "    "+name+": "+field.Type+",")
		}
		shapes = append(shapes, strings.Join(append(lines, "}"), "\n"))
	}
	return strings.Join(shapes, "|")
}

func renderFormRecord(w *writer, d *effective.FormRecord) {
	w.line("        'form_record' => [")
	w.line("            'php' => 'record',")
	w.line("            'discriminator' => 'type',")
	w.line(fmt.Sprintf("            'required' => %t,", d.Required))
	if d.Untagged {
		w.line("            'untagged' => true,")
	}
	w.line("            'variants' => [")
	for _, v := range d.Variants {
		w.line("                " + phpString(v.Tag) + " => [")
		for _, f := range v.Fields {
			w.line(fmt.Sprintf("                    %s => ['type' => %s, 'required' => %t],", phpString(f.Name), phpString(f.Type), f.Required))
		}
		w.line("                ],")
	}
	w.line("            ],")
	w.line("        ],")
}

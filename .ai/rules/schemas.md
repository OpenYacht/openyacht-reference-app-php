---
paths:
    - 'resources/schemas/**'
---

# Schemas

## Vendored schemas stay byte-identical to upstream; adopt amendments by copying, then fixing what fails

resources/schemas/v1 is a byte-identical copy of OpenYacht/protocol schemas/v1 (Prettier-ignored; the weekly protocol-drift workflow diffs it byte for byte). Never hand-edit or reformat these files. The protocol is amended without a version bump, so protocol_versions never signals drift — the missed `quantity` amendment served schema-invalid listings for 26 days (2026-09-18). To adopt an amendment: copy upstream schemas/v1 over the directory, run SchemaConformanceTest, fix what it reports, and if the wire form of a listing changed add a migration stamping federation_updated_at for the affected listings (see migrations rules). Every new emitted document type gets a case in SchemaConformanceTest, through the HTTP endpoint, not the serializer.

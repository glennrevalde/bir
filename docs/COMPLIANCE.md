# Compliance matrix and sources

Research checkpoint: 16 October 2026. Current validator recorded in System settings: Alphalist Data Entry and Validation Module v7.4 (RMC 15-2025), with the ATC patch from the BIR advisory of 18 November 2025. Update both when BIR releases a new module.

## Ground rules the code enforces

- **No invented formats.** Every byte of a DAT comes from a row in the layout registry (`layout_specs`, `spec_records`, `spec_fields`). Each layout and each field carries a source reference and a confidence level.
- **Three separate levels.** Level 1 data quality, Level 2 DAT structure (independent re-parse), Level 3 official BIR validator evidence. They are stored and shown separately. Levels 1 and 2 can never mark a file as BIR-validated.
- **Submission ready means something specific.** A file is labelled `submission_ready` only when Levels 1 and 2 pass, its layout is approved, and every ATC it uses is approved. Anything else is an `unvalidated_draft`, and draft downloads need their own permission and are logged as such.
- **Evidence is human.** Level 3 is recorded by uploading the validator's own report. A different user must review it. A report naming another TIN, form or period is refused; if a DAT is uploaded with the report, its SHA-256 must match the generated file.
- **Approval is maker-checker.** Layouts, ATCs, file release, period unlocks and certificate voids each need a second person with the approve permission.
- **One serializer.** Excel import and direct entry feed the same dataset builder and the same writer. The test suite checks that both produce identical bytes.

## Layout status as shipped

Status `draft` means unsourced or analog. `pending_verification` means sourced but not yet confirmed on this installation's validator. No layout ships `approved`: approval needs a validator run on your own machine.

| Form | Schedule | Status | Field confidence | Basis |
|---|---|---|---|---|
| SLS | Sales | pending verification | medium | Two independent RELIEF generators agree on field order; quoting follows RELIEF v2.3 |
| SLP | Purchases | pending verification | medium | Same sources as SLS |
| SLI | Importations | pending verification | low | One secondary source |
| 1601-EQ | Schedule 1 | pending verification | medium | Header shape from the official MAP header (RMC 25-2024 Annex A); detail and control from one secondary generator; filename per Annex B |
| 1601-EQ | Schedule 2 | draft | analog | No published field table found; mirrors Schedule 1 |
| 1601-FQ | Schedules 1 to 3 | draft | analog | No published field table found; mirrors 1601-EQ |
| 1600-VT, 1600-PT | Schedule 1 | draft | official header, analog detail | Header and filename from RMC 25-2024 Annex A/B; detail mirrors QAP |
| 1604-C | Schedule 1 | pending verification | validated | Reproduces byte for byte a v7.4 TY2025 sample whose validator report reads "No Errors Encountered" (see `tests/fixtures/README.md`) |
| 1604-C | Schedule 2 (MWE) | draft | analog | Column set from the printed schedule (RMC 160-2022 attachment); DAT positions unconfirmed |
| 1604-E | Schedules 3 and 4 | draft | analog | Annex lists the schedules but the published PDF is image-only |
| 1604-F | Schedules 4 to 6 | draft | analog | Same as 1604-E |
| SAWT | generic | pending verification | low | One secondary generator (quoted shape noted for 1701/1701Q) |
| SAWT | 1702-Q variant | pending verification | low | One secondary generator describing an unquoted, untrimmed 1702-Q shape |
| SRS | Semestral | draft | analog | RR 14-2008 / RMC 5-2009; a secondary source reports RMC 122-2019 ended SRS submission. Confirm before use |

Each layout page (Admin, File layouts) shows these notes per field, lets you clone a layout into a new draft version with its own effective dates, edit it, and send it for approval.

### Getting a layout to approved

1. Generate a file with the layout from real or test records.
2. Run it through the current BIR validation module.
3. Upload the validator report on the file page. If it fails, fix the layout (clone, edit) or the data, regenerate, and repeat.
4. Once a file made with the layout is accepted, request approval of the layout. A reviewer approves it.
5. From then on, files made with it can reach `submission_ready`.

## ATC registry

Eighteen expanded withholding ATCs are seeded from the 1601-EQ January 2019 ATC annex, all as `pending_review`. Check each against the ATC table installed with your validator patch, correct anything that differs, and approve. Rates are versioned by effective date. Records with an unapproved ATC can be saved and generated, but the file stays an `unvalidated_draft`.

## Certificates

2307, 2306 and 2316 are drawn as system replicas of the January 2018 (ENCS) layouts and stamped with that template revision in the certificate record. Check each form's current revision on the BIR website before issuing, and change the template if BIR has revised it. Voids need approval; reissues create a new revision and keep the old one.

## Research and verification pending

These items could not be confirmed from a published BIR source at build time and are labelled in the app:

- DAT field tables for 1601-FQ, 1604-E, 1604-F, 1604-C Schedule 2 and QAP Schedule 2 (published annexes are image-only)
- Detail and control records for the MAP (1600-VT, 1600-PT)
- SAWT shapes for forms other than 1701/1701-Q and 1702-Q
- Current applicability of SRS submission
- Filing deadline rules: none are pre-filled. Add them under Admin, RDO codes and deadlines, each with its BIR source
- RDO code list: load the current list from the BIR website (two-column CSV)

## Sources consulted

Official issuances (as cited in the layout registry):

- RMC 25-2024 Annexes A and B (MAP header record and file naming), RMC 15-2025 (Alphalist module v7.4)
- RMC 7-2021 Annex C (QAP job aid: one file per month of the quarter)
- RMC 160-2022 attachment (1604-C schedules and code lists)
- RR 14-2008 and RMC 5-2009 (SRS); RMC 122-2019 (reported termination of SRS)
- 1601-EQ January 2019 ATC annex

Secondary sources used only where no official table was obtainable, and flagged at field level:

- github.com/ReyJrFerrer/BIR-dat: 1604-C v7.4 sample and its validator report (test fixture)
- github.com/cpa-coder/vat-relief: RELIEF v2.3 generator (SLS, SLP)
- github.com/AlmyrCPA/Haki-Bookkeeping-System: RELIEF, QAP and SAWT generators
- Forvis Mazars Philippines: verbatim reproduction of the RMC 25-2024 MAP header

The reference site named in the brief (bir-excel-uploader.com) served as a workflow reference only. No layout in the registry is taken from it.

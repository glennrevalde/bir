# Compliance matrix and sources

Research checkpoint: 16 October 2026. Current validator recorded in System settings: Alphalist Data Entry and Validation Module v7.4 (RMC 15-2025), with the ATC patch from the BIR advisory of 18 November 2025. Update both when BIR releases a new module.

## Ground rules the code enforces

- **No invented formats.** Every byte of a DAT comes from a row in the layout registry (`layout_specs`, `spec_records`, `spec_fields`). Each layout and each field carries a source reference and a confidence level.
- **Three separate levels.** Level 1 data quality, Level 2 DAT structure (independent re-parse), Level 3 official BIR validator evidence. They are stored and shown separately. Levels 1 and 2 can never mark a file as BIR-validated.
- **Submission ready means something specific.** A file is labelled `submission_ready` only when Levels 1 and 2 pass, its layout is approved, and every ATC it uses is approved. Anything else is an `unvalidated_draft`, and draft downloads need their own permission and are logged as such.
- **Evidence is human.** Level 3 is recorded by uploading the validator's own report (reviewed by a different user when maker-checker is on). A report naming another TIN, form or period is refused; if a DAT is uploaded with the report, its SHA-256 must match the generated file.
- **Approval follows the validator.** A file is approved as soon as its official BIR validator report shows no errors, and the layout that produced it is approved with that evidence. Layouts, ATCs, period unlocks and certificate voids take effect when saved and are logged as approved. Platform settings can turn maker-checker back on, so each of these needs a second person with the approve permission.
- **One serializer.** Excel import and direct entry feed the same dataset builder and the same writer. The test suite checks that both produce identical bytes.

## Layout status as shipped

Status `draft` means unsourced or analog. `pending_verification` means sourced but not yet confirmed on this installation's validator. No layout ships `approved`: approval needs a validator run on your own machine.

| Form | Schedule | Status | Field confidence | Basis |
|---|---|---|---|---|
| SLS | Sales | pending verification | medium | Two independent RELIEF generators agree on field order; quoting follows RELIEF v2.3 |
| SLP | Purchases | pending verification | medium | Same sources as SLS |
| SLI | Importations | pending verification | low | One secondary source |
| 1601-EQ | Schedules 1 and 2 | pending verification | official | Written as the BIR Alphalist Data Entry and Validation module v7.4 writes them (mn1601e) and checked against its validator (val1601e): HQAP header, D1 with rate and tax and its C1, D2 with income only and its C2, all in one file per month |
| 1601-FQ | Schedules 1 to 3 | pending verification | official | Module writer mn1601f, validator val1601f: sequence after the period; Schedule 2 fringe benefit, grossed-up monetary value and tax; Schedule 3 payee status code A to H and income; one file per month |
| 1600-VT, 1600-PT | Schedule 1 | pending verification | official | Module writers mn1600 and mn1600pt, validator val1600: HMAP header, DMAP lines with an empty nature-of-income column after the ATC, CMAP control |
| 1604-C | Schedule 1 | pending verification | validated | Reproduces byte for byte a v7.4 TY2025 sample whose validator report reads "No Errors Encountered" (see `tests/fixtures/README.md`) |
| 1604-C | Schedule 2 (MWE) | pending verification | official | 59-field D2 and 45-field C2 lines as written by the BIR Alphalist Data Entry and Validation module v7.4 (sched2dets) and checked by its validator; replaces the earlier unsourced draft, which is retired |
| 1604-E | Schedules 3 and 4 | pending verification | official | Module writer mn1604e, validator val1604e: H1604E header with 12/31/YYYY; D3 with income, rate and tax and a C3 with the tax total; D4 with income only and a C4 with the income total; one file |
| 1604-F | Schedules 4 to 6 | pending verification | official | Module writer mn1604f, validator val1604cf: D4 with the payee status code, rate and tax; D5 fringe benefits; D6 exempt payees with status code; one file |
| SAWT | every attached return | pending verification | official | Module writer (sawt form) and validator (val_sawt): HSAWT/DSAWT/CSAWT with the attached return in the form code (H1702Q, D2550Q, ...). Replaces the two earlier secondary-source SAWT layouts, which are retired |
| SRS | Semestral | draft | analog | RR 14-2008 / RMC 5-2009; a secondary source reports RMC 122-2019 ended SRS submission. Confirm before use |

The QAP (1601-EQ, 1601-FQ), 1604-C, 1604-E and 1604-F are each generated as one file holding all their schedules, as the BIR module writes them; a schedule without records is left out. The 1604-C is generated as one file holding both schedules, because the BIR system does not separate them: the H1604C header once, the D1 lines and their C1 control, then the D2 lines and their C2 control. Each schedule numbers its lines from 1 and its control line totals only its own lines. A year without minimum wage earners gives exactly the validated Schedule 1 bytes. A validator pass on a combined file approves both layouts. The Level 1 checks follow the formulas of the v7.4 validator for both schedules: totals, net taxable equal to taxable compensation, tax due on the graduated rates, tax due equal to the actual amount withheld, the 90,000.00 ceiling on non-taxable 13th month pay and the accepted region codes. The test suite runs generated files through a port of those checks, and the QAP, MAP, 1604-E, 1604-F and SAWT files through ports of the module's other validators (`tests/bir_v74_withholding.php`, `tests/bir_v74_sawt.php`).

The 1604-E Schedule 3 reads the year's QAP Schedule 1 lines (placement A) and year totals entered from a 1604-E alphalist (placement Y, which the monthly QAP leaves out); Schedule 4 reads the payees exempt from withholding (placement B, the 1601-EQ Schedule 2). Earlier versions read Schedule 3 and 4 the other way round. The 1601-FQ and 1604-F payee status code is derived from the payee (individual A, corporation E, or D and G with a foreign address). A fringe benefit line is stored with the grossed-up monetary value as its income; the benefit written to the file is that value less the tax.

Each layout page (Admin, File layouts) shows these notes per field, lets you clone a layout into a new draft version with its own effective dates, edit it, and send it for approval.

### Getting a layout to approved

1. Generate a file with the layout from real or test records.
2. Run it through the current BIR validation module.
3. Upload the validator report on the file page. If it fails, fix the layout (clone, edit) or the data, regenerate, and repeat.
4. When the validator passes a file made with the layout, the layout is approved with that evidence. (With maker-checker on, request approval of the layout and a reviewer approves it.)
5. From then on, files made with it can reach `submission_ready`.

## ATC registry

Eighteen expanded withholding ATCs are seeded from the 1601-EQ January 2019 ATC annex, and the VAT (WV), percentage tax (WB), final tax and fringe benefit (WF type) codes from the ATC table of the BIR Alphalist module v7.4, all as `pending_review`. Level 1 also reports an ATC that is not in the module's own ATC table (`app/specs/bir_v74_atc.php`, 469 codes), which its validator refuses. Check each against the ATC table installed with your validator patch, correct anything that differs, and approve. Rates are versioned by effective date. Records with an unapproved ATC can be saved and generated, but the file stays an `unvalidated_draft`.

## Certificates

2307, 2306 and 2316 print as the BIR Alphalist Data Entry and Validation Module version 7.4 prints them. The blank forms are drawn from the module's own templates (2307.xltx and 2306_1.xltx, January 2018 (ENCS); 2316(1).xltx, September 2021 (ENCS)), stored in `app/specs/forms/` with their geometry in `app/specs/bir_forms.php`: the BIR seal, the PDF417 barcode read module by module from the module's images (it decodes to `2307 01/18ENCS`, and to `2316 01/18ENCS` on the 2316 and the 2306, exactly as the module prints), and the boxes the module writes each value to, by the module's shape names. The values follow the module's print routines: period month, day and year in separate boxes, TIN in groups, Total cells showing a dash for zero, 2316 items 25A to 28 computed as the module does. Three places differ on purpose: the 2306 payee and payor ZIP codes go in their own boxes (the module writes the payor's ZIP in the payee's box), the 2306 CONFORME carries only the payee's name (the module repeats the payor's signatory there), and 2316 items 53 and 54 are filled only under substituted filing. Each certificate record is stamped with its template revision (`... BIR v7.4`). Reissues create a new revision and keep the old one.

## Research and verification pending

These items could not be confirmed from a published BIR source at build time and are labelled in the app:

- Whether the BIR's own systems share two quirks of the v7.4 validator: a 1601-FQ Schedule 3 line numbered 10 or more is reported as "Status Code is Invalid", and the 1601-FQ and 1604-F control totals are never compared
- Current applicability of SRS submission
- Filing deadline rules: none are pre-filled. Add them under Admin, RDO codes and deadlines, each with its BIR source
- RDO code list: load the current list from the BIR website (two-column CSV)

## Sources consulted

Official issuances (as cited in the layout registry):

- RMC 25-2024 Annexes A and B (MAP header record and file naming), RMC 15-2025 (Alphalist module v7.4)
- BIR Alphalist Data Entry and Validation Module v7.4 itself: its file writers and validators (QAP, MAP, 1604-C, 1604-E, 1604-F, SAWT), its ATC table and its certificate templates
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

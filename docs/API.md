# Token API

`api.php` lets another system (a payroll, POS or accounting package) read file status and the directory, post records, and fetch files that already have accepted BIR validator evidence. It cannot generate files, record evidence or approve anything; those stay with people in the app.

## Tokens

Create a token under Admin, API tokens. Each token belongs to the user who made it and is limited by:

- **Scopes**: `records:read`, `records:write`, `files:read`
- **Client**: one client, or any client in the user's scope
- **Expiry**: optional
- **The user's role**: the API also checks the owner's permissions for the client (`records.view`, `records.edit`, `dat.download`), so a token never does more than its owner could in the app

The token is shown once. Only its SHA-256 is stored. Revoke it from the same page. Every write and download is written to the audit trail with the token name.

Send it on every request:

```
Authorization: Bearer gsb_<48 hex characters>
```

On Apache with PHP as CGI/FastCGI the Authorization header may be dropped. The bundled `.htaccess` does not rewrite requests; if you get 401 with a correct token, add this to the root `.htaccess`:

```
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

## Choosing the client

Pass `client_id` as a query parameter (GET) or in the JSON body (POST). A token tied to one client refuses any other.

## Endpoints

### GET `api.php?r=files&client_id=1`

Scope `files:read`. Lists the client's current (not superseded) DAT files, newest first, up to 500.

```json
{"ok": true, "client": {"id": 1, "tin": "111222333"},
 "files": [{"id": "8", "form_code": "1601EQ", "schedule_code": "S1", "period_label": "Sep 2026",
            "filename": "11122233300000920261601EQ.DAT", "sha256": "9311e6...", "status": "pending_bir",
            "file_label": "unvalidated_draft", "record_count": "6", "total_base": "412500.00", "total_tax": "9875.00",
            "origin": "generated", "created_at": "2026-10-16 21:36:07"}]}
```

`status` is one of `structure_failed`, `pending_bir`, `bir_failed`, `bir_validated`, `approved`, `submitted`. `file_label` is `submission_ready` or `unvalidated_draft`.

### GET `api.php?r=file&id=8&client_id=1`

Scope `files:read`, permission `dat.download`. Returns the DAT bytes as `application/octet-stream`, with the recorded checksum in `X-Content-SHA256`. Only files whose status is `bir_validated`, `approved` or `submitted` are served; anything else returns 409. Drafts are downloaded in the app, where the download is labelled and logged as a draft.

### GET `api.php?r=directory&client_id=1`

Scope `records:read`. Customers, suppliers, payees and payors (up to 5000) with TIN, branch code, names, address and default ATC.

### POST `api.php?r=withholding` | `r=withheld_on_us` | `r=sales` | `r=purchases`

Scope `records:write`, permission `records.edit`. Body:

```json
{
  "client_id": 1,
  "rows": [
    {
      "branch_code": "0000",
      "party": {"tin": "123-456-789", "branch_code": "0000", "entity_type": "non_individual",
                "registered_name": "SAMPLE SUPPLY CORP", "address1": "Makati City", "zip_code": "1200"},
      "atc_code": "WC158",
      "payment_date": "2026-09-15",
      "income_amount": "10000.00",
      "tax_withheld": "100.00",
      "schedule_tag": "A"
    }
  ]
}
```

Between 1 and 1000 rows per request. Each row is validated and saved on its own; the response says which succeeded:

```json
{"ok": true, "results": [{"index": 0, "ok": true, "id": 61},
                         {"index": 1, "ok": false, "error": "Party: TIN was stored as a number in scientific notation ..."}]}
```

Common row fields:

| Field | Notes |
|---|---|
| `branch_code` | The client's own branch. Omit for the head office |
| `party` | The counterparty. Matched to the directory by TIN and branch, or created. Individuals need `entity_type: "individual"`, `last_name`, `first_name`; others need `registered_name`. Optional: `middle_name`, `address1`, `address2`, `zip_code` (4 digits), `email`, `default_atc` |

Withholding (`r=withholding`):

| Field | Notes |
|---|---|
| `tax_kind` | `EWT` (default), `FWT`, `GVAT`, `GPT` |
| `atc_code` | Must exist in the ATC registry for the payment date |
| `payment_date` | `YYYY-MM-DD` |
| `income_amount` | Required. Decimal string, two places |
| `tax_rate` | Optional. Taken from the ATC registry when blank |
| `tax_withheld` | Optional. Computed from the rate when blank. A value that differs from the computed tax needs `rate_exception_note` |
| `schedule_tag` | `A` (default), `B` or `C`: which QAP/alphalist schedule the line belongs to |
| `nature_of_payment`, `reference_no` | Optional text |

Taxes withheld on us (`r=withheld_on_us`), the columns of the SAWT. `party` is the payor (the customer who withheld):

| Field | Notes |
|---|---|
| `period_month` | `YYYY-MM`, the month of the SAWT. Required unless `payment_date` is given |
| `payment_date` | Optional. `YYYY-MM-DD`, inside `period_month` |
| `atc_code` | Must exist in the ATC registry for the month and fit the client as payee (WC codes for a non-individual, WI codes for an individual) |
| `nature_of_payment` | Optional. Blank uses the ATC description |
| `income_amount` | Required. Amount of income payment |
| `tax_rate` | Optional. Percent; blank uses the ATC rate |
| `tax_withheld` | Optional. Blank computes ROUND(income x rate / 100, 2). A different amount is kept, as certified on the 2307, with a warning |
| `reference_no`, `rate_exception_note` | Optional text: the 2307 reference and a note |

Sales (`r=sales`), the same fields as the VAT sales workbook:

| Field | Notes |
|---|---|
| `invoice_no`, `invoice_date` | The date (`YYYY-MM-DD`) is required and sets the month |
| `gross_taxable` | Optional. Taxable sales with VAT. Not stored: when given, blank `taxable_amount` and `output_vat` are worked out from it, and the three must add up |
| `exempt_amount`, `zero_rated_amount` | Optional |
| `taxable_amount` | Net of VAT. Blank with a gross: ROUND(gross / (1 + rate / 100), 2) |
| `vat_rate` | Percent. Blank means 12 |
| `output_vat` | Blank computes it (gross - taxable, or ROUND(taxable x rate / 100, 2)). A different amount is kept with a warning |
| `description` | Optional |

The party's `address1` and `address2` are the workbook's SUBSTREET, STREET, BARANGAY and DISTRICT, CITY, ZIP CODE.

Purchases (`r=purchases`), the same fields as the R_Purchases workbook:

| Field | Notes |
|---|---|
| `period_month` | `YYYY-MM`. Required unless `invoice_date` is given |
| `exempt_amount`, `zero_rated_amount` | Optional |
| `services_amount`, `capital_goods_amount`, `other_goods_amount` | Net of VAT. Their sum is the taxable amount (taxableNetofVat) |
| `vat_rate` | Percent. Blank means 12 |
| `input_vat` | Blank computes ROUND(taxable x rate / 100, 2). A different amount is kept with a warning |
| `invoice_no`, `invoice_date`, `non_creditable_vat`, `description` | Optional. An invoice date must fall inside `period_month` |

At least one amount must be non-zero.

Rows into a locked month, rows that duplicate an existing posted record, and rows with an unknown ATC or branch are refused with a message.

## Errors

| HTTP | Meaning |
|---|---|
| 401 | Missing, malformed, revoked or expired token, or the owner is disabled |
| 403 | Missing scope, the owner's role lacks the permission, or the token is limited to another client |
| 404 | Unknown endpoint, or the client or file is not in scope |
| 409 | File has no accepted validator evidence |
| 422 | Request body is missing `rows` or has more than 1000 |

Error bodies look like `{"ok": false, "error": "..."}`.

## Example

```
curl -H "Authorization: Bearer gsb_..." "https://your-server/gsmart-bir/api.php?r=files&client_id=1"
```

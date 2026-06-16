# Celtac Lab Report System
### Lab Information & Invoice Management Platform

**Version:** 2.0  
**Stack:** PHP · MySQL · Bootstrap 5 · DataTables · TCPDF  
**Server:** onlinereport.celtaclab.com  
**Database:** `celreport`

---

## Table of Contents

1. [System Overview](#system-overview)
2. [Database Structure](#database-structure)
3. [Core Modules](#core-modules)
4. [User Roles](#user-roles)
5. [Invoice Workflow](#invoice-workflow)
6. [PDF Generation](#pdf-generation)
7. [File Structure](#file-structure)
8. [Key Business Rules](#key-business-rules)
9. [Known Configurations](#known-configurations)
10. [Troubleshooting](#troubleshooting)

---

## System Overview

Celtac Lab Report System is a web-based Lab Information System (LIS) for **บริษัท เซลแทค แล๊บ จำกัด**. It manages clinic accounts, patient lab results, and invoice/quotation generation for B2B laboratory services.

**Core capabilities:**
- Multi-clinic user management with role-based access
- Patient registration and lab result recording
- Price list management with auto-generated test codes (C001, C002…)
- Invoice creation from price list with per-test discount support
- PDF output: Patient report, Quotation, Patient Quotation, All-Tests PDF, Receipt
- Admin email notifications on order completion

---

## Database Structure

**Database name:** `celreport`

| Table | Purpose |
|---|---|
| `clinics` | Clinic accounts (name, tax_id, address, phone, email) |
| `users` | System users (superadmin / admin / clinic_user roles) |
| `patients` | Patient records linked to a clinic |
| `lab_tests` | Test name templates per clinic |
| `lab_results` | Individual patient test results |
| `lab_comments` | Test group comments per patient |
| `patient_reports` | Uploaded PDF report files per patient |
| `imported_prices` | Master price list (test_code, test_name, specimen, method, tat_day, price) |
| `imported_prices_backup` | Backup of price list |
| `invoices` | Invoice headers (invoice_number, clinic_id, patient_name, tax_id, tel, address, salesman_no) |
| `test_prices` | Invoice line items (clinic_id, invoice_id, test_code, test_name, price, discount_percent) |

### Invoice Number Format
```
Q-YYMM####
Example: Q-25060001  (June 2025, sequence #0001)
Sequence resets every month.
```

### Test Code Format
```
C001, C002, C003 … C999+
Auto-incremented in imported_prices and test_prices tables.
```

---

## Core Modules

### 1. Price List (`import_prices.php`)
- Displays all tests from `imported_prices` via server-side DataTables (AJAX → `ajax_table_data.php`)
- Add new test manually (code auto-generated)
- Edit/delete existing tests via modal
- Alphabet filter (A–Z) + custom search box
- CSV bulk import
- **Create Invoice** button launches clinic selector modal → redirects to `create_invoice.php`

### 2. Create Invoice (`create_invoice.php`)
- Select a clinic, then pick tests from the full price list
- Supports editing price and discount % per test before creating
- **Select All** checkbox with chunked processing and loading overlay (288+ rows)
- Custom tests can be added (code auto-assigned)
- Selections persist in `localStorage` per clinic (survives page reload/search)
- On submit → inserts into `invoices` + `test_prices`, redirects to invoice list

**Deduplication key:** `test_code` (when available) — prevents same-named tests from collapsing.

### 3. Invoice List (`invoice_list.php`)
- Lists all invoices with clinic name, invoice number, date
- Links to `test_prices_by_clinic.php` for editing

### 4. Test Prices by Clinic (`test_prices_by_clinic.php`)
- View/edit all line items for a specific invoice
- Add tests via autocomplete search (`fetch_test_names.php`)
- Delete individual rows (tracked via `deleted_ids_json` hidden field)
- Live invoice summary: Subtotal, Total Discount, Net Total
- Customer info strip (patient name, tax ID, tel, address) with Edit modal
- Buttons: **Save Changes · Patient PDF · Quotation · All Tests PDF · Receipt PDF**

### 5. Patient Management
- `add_patient.php` — register new patient
- `search_patients.php` — search by name/HN across clinics
- Patient detail page — view/enter lab results, upload PDF reports

### 6. Send Email (`send_email.php`)
- Send lab result notification emails to clinic contacts

---

## User Roles

| Role | Access |
|---|---|
| `superadmin` | Full access to all clinics, price list, invoices, users |
| `admin` | Manage clinics and invoices |
| `clinic_user` | View/manage own clinic patients and results only |

Role is stored in `users.role` (ENUM).  
Session check is handled by `includes/session_check.php`.

---

## Invoice Workflow

```
Price List (import_prices.php)
        │
        ▼
Create Invoice (create_invoice.php)
  • Select clinic
  • Pick tests + set price/discount
  • Click "Create Invoice"
        │
        ▼
invoice created in DB
  invoices (header) + test_prices (288 line items)
        │
        ▼
Test Prices by Clinic (test_prices_by_clinic.php)
  • Review / adjust prices
  • Add customer info (patient name, tax ID, address)
  • Save Changes
        │
        ├──► Patient PDF       (generate_pdf_patient.php)
        ├──► Quotation PDF     (generate_pdf_patient_quotation.php)
        ├──► All Tests PDF     (generate_pdf_alltests.php)
        └──► Receipt PDF       (generate_receipt.php)
```

---

## PDF Generation

All PDFs use **TCPDF** via a shared base class in `pdf_common.php`.

| File | Output | Key Data |
|---|---|---|
| `generate_pdf_patient.php` | Patient-facing price list (ใบเสนอราคา) | test_prices JOIN clinic |
| `generate_pdf_patient_quotation.php` | Full quotation with specimen/method/TAT columns | test_prices LEFT JOIN imported_prices |
| `generate_pdf_alltests.php` | Complete price list for clinic (not invoice-specific) | imported_prices |
| `generate_receipt.php` | Receipt/tax invoice | test_prices + invoice meta |

**PDF save directory:** `generated_quotes/`  
**Letterhead assets:** `assets/celtaclogo.png`, `assets/medicallogo.jpg`, `assets/linepng.jpg`

### Thai Baht Text
`thai_baht_text($amount)` — converts numeric total to Thai words (defined in `pdf_common.php`).

---

## File Structure

```
/
├── includes/
│   ├── db.php                  # MySQL connection ($conn)
│   ├── session_check.php       # Auth guard
│   ├── header.php              # Nav + Bootstrap
│   └── footer.php              # Footer + JS
│
├── assets/
│   ├── celtaclogo.png
│   ├── medicallogo.jpg
│   └── linepng.jpg
│
├── generated_quotes/           # Saved PDF files (auto-created)
│
├── pdf_common.php              # Shared TCPDF class + thai_baht_text()
│
├── import_prices.php           # Price list management
├── ajax_table_data.php         # Server-side DataTables handler
├── fetch_test_names.php        # Autocomplete endpoint
│
├── create_invoice.php          # Invoice creation from price list
├── invoice_list.php            # All invoices
├── test_prices_by_clinic.php   # Invoice detail / edit
│
├── generate_pdf_patient.php            # Patient PDF
├── generate_pdf_patient_quotation.php  # Full quotation PDF
├── generate_pdf_alltests.php           # All tests PDF
├── generate_receipt.php                # Receipt PDF
│
├── save_patient_name.php       # AJAX: save patient name to invoice
├── save_receipt_info.php       # AJAX: save tax_id/tel/address/salesman_no
│
├── add_patient.php
├── search_patients.php
├── dashboard.php
├── manage_users.php
├── manage_clinics.php
└── send_email.php
```

---

## Key Business Rules

1. **Test codes are unique per `imported_prices`** — format `C001`–`C999+`, auto-incremented, never reused.

2. **Invoice deduplication uses `test_code`** (not test name) — tests with identical names but different codes are preserved as separate line items.

3. **Invoice number resets monthly** — sequence `####` counts from 0001 each calendar month per `Q-YYMM` prefix.

4. **Price on invoice is independent of master price list** — editing `imported_prices` does not retroactively change existing invoices. Each `test_prices` row stores its own price at time of invoice creation.

5. **Discount is per line item** — `discount_percent` in `test_prices`. Net price = `price × (1 - discount_percent/100)`.

6. **Customer info is stored on the invoice** — `patient_name`, `tax_id`, `tel`, `customer_address`, `salesman_no` fields on `invoices` table, editable via the customer modal on `test_prices_by_clinic.php`.

7. **PDF quotation joins `imported_prices`** on normalized `LOWER(TRIM(test_name))` to pull specimen/method/TAT columns that aren't stored on `test_prices`.

---

## Known Configurations

| Setting | Value |
|---|---|
| Timezone | Asia/Bangkok |
| Default clinic for testing | clinic_id = 4 (Celtac Lab) |
| Company tax ID | 0105562129981 |
| Company address | 221 ซอยอินทามระ 33 แยก 2 ถนนสุทธิสารวินิจฉัย แขวงรัชดาภิเษก เขตดินแดง กรุงเทพฯ 10400 |
| Company phone | 0-2275-2498 |
| Company fax | 0-2076-6288 |

---

## Troubleshooting

### Invoice has fewer items than expected (e.g. 250 instead of 288)

**Cause:** Old invoice was created before the dedup fix — name-based deduplication collapsed tests with identical names.

**Fix:** Run in phpMyAdmin (adjust `clinic_id` and `invoice_id`):
```sql
INSERT INTO test_prices (clinic_id, invoice_id, test_code, test_name, price, discount_percent, created_at)
SELECT 4, <invoice_id>, ip.test_code, ip.test_name, ip.price, 0, NOW()
FROM imported_prices ip
WHERE ip.test_code NOT IN (
    SELECT test_code FROM test_prices 
    WHERE invoice_id = <invoice_id> AND test_code IS NOT NULL
)
ORDER BY ip.test_code;
```

---

### Test shows price 0.00

**Cause:** The `imported_prices` row for that test has `price = 0`.  
**Fix:** Go to Price List → edit the test → set the correct price. Existing invoices won't update automatically — edit the price directly on `test_prices_by_clinic.php`.

---

### PDF generates only 250 rows

Same root cause as above — the invoice in `test_prices` only has 250 rows. The PDF mirrors exactly what's in `test_prices` for that invoice. Patch the DB with the SQL above, then regenerate.

---

### Scrollbar not visible on tables

If `overflow: hidden` is set on a parent card element, the scrollbar is clipped. The fix is to remove `overflow: hidden` from the card and use `overflow-y: scroll` on the inner scroll wrapper only.

---

### DataTables pagination duplicating

Caused by manually moving DataTables DOM elements via `initComplete`. Fix: use DataTables native `scrollY: '520px'` option — it handles its own scroll container and pagination stays in one place.

---

### WooCommerce stale nonces (if applicable)

WP Fastest Cache can serve cached pages with expired nonces. Fix: dedicated nonce-fetch AJAX endpoint that bypasses cache.

---

*README generated for Celtac Lab Report System — maintained by C2 Dev (HEAT)*

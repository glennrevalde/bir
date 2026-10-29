# Security and data privacy

The system holds taxpayer identification numbers, income and tax figures for third parties, and employee compensation. Under the Data Privacy Act of 2012 (RA 10173) the firm running it is the personal information controller or processor. This page lists what the software does and what remains the operator's job.

## What the software does

**Access**
- Every organization is a separate tenant. Every query is scoped by organization and client, and the test suite checks that one firm cannot read another's records.
- Nine roles (firm owner, firm administrator, client administrator, preparer, encoder, reviewer, signatory, auditor, support) built from 33 permissions. Members can be limited to selected clients and branches.
- Approvals: off by default. A file is approved when its BIR validator report shows no errors, and unlocks, voids, tax codes, layouts and signature images take effect when a permitted user saves them; each is logged as `approval.auto` in the audit trail. With Platform settings, "Require a second person's approval", turned on, maker-checker applies: the person who requests a file release, period unlock, layout or ATC approval, or certificate void cannot approve it, and validator evidence must be reviewed by someone other than the person who uploaded it.

**Sign-in**
- Passwords hashed with PHP `password_hash` (bcrypt by default), minimum 10 characters with letters and numbers.
- Five failed attempts per email or 25 per IP address within 15 minutes blocks further attempts.
- Optional two-step sign-in (TOTP, any authenticator app). It can be required for privileged roles on the System page. Secrets are encrypted with AES-256-GCM using `app_key`.
- Sessions use HttpOnly, SameSite=Lax cookies, are regenerated at sign-in, and end after an idle period (30 minutes by default).
- All forms carry a CSRF token. Responses send `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy` and `Permissions-Policy`.

**Records and files**
- Hash-chained audit trail: each event stores the hash of the previous one. Admin, Audit trail has a Verify button that finds the first altered or deleted event.
- Records keep revisions; saved records in a locked month cannot be changed.
- Generated files are stored with their SHA-256. A download whose bytes no longer match is refused and logged.
- Uploads are checked by extension and content signature, size-limited, and stored under random names in the storage folder, which the web server does not serve.
- Client and system backups can be encrypted with a passphrase (AES-256-GCM, PBKDF2-SHA256 with 200,000 iterations). The passphrase is not stored; losing it loses the backup.

**No outside services**
- No analytics, tracking, AI service or CDN. Fonts, icons and the QR code library are bundled. The browser and server never contact a third party.
- The only outbound traffic is email, and only when you switch mail mode to PHP `mail()`.

## What the operator must do

- Run it over HTTPS and set `'secure_cookies' => true` in `config.php`.
- Put `storage_path` outside the web root, and keep `config.php` readable only by the web server user.
- Generate a unique `app_key` and keep a copy somewhere safe. Changing it makes MFA enrolments and app-key encrypted data unreadable.
- Change the superadmin password at first sign-in (forced) and remove the demo accounts and demo firm before loading real data. Import `gsmart_bir_clean.sql` for production.
- Delete `check.php` after installation (it only answers requests from the server itself).
- Require two-step sign-in for privileged roles.
- Take encrypted backups on a schedule and store them off the server. Test a restore.
- Register your data processing system and appoint a Data Protection Officer as the National Privacy Commission requires for your organization, run a privacy impact assessment, and sign data sharing or outsourcing agreements with each client.
- Set a retention policy that meets BIR record-keeping rules and the Data Privacy Act. The system archives clients but does not purge data automatically.
- Review the audit trail and the member list regularly, and remove access for people who leave.

## Web server rules

Apache: the bundled `.htaccess` files deny web access to `app/`, `database/`, `docs/`, `storage/`, `tests/` and `tools/`, block `config.php`, `.sql`, `.md` and dot files at the root, and turn off directory listings. They work on Apache 2.2 and 2.4. `AllowOverride All` must be on for the folder.

Nginx equivalent:

```
location ~ ^/gsmart-bir/(app|database|docs|storage|tests|tools)/ { deny all; }
location ~ ^/gsmart-bir/(config\.php|config\.sample\.php|check\.php)$ { deny all; }
location ~ /\.  { deny all; }
location ~ \.(sql|md|log)$ { deny all; }
```

## Reporting a problem

If you find a security issue, keep the details private, note the page and steps, and fix it or report it to whoever maintains your installation.

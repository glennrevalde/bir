<?php
/**
 * G-SMaRT BIR Integration System User's Manual.
 *
 * Chapters hold sections. A section key that equals a page name (for example "withholding") is also the
 * Help drawer article for that page. {img:name} becomes assets/img/manual/name.jpg and {go:page} a link to that page.
 * Section fields: title, page (opened by "Go to this page"), summary (one line), html.
 */
$S = function ($title, $page, $summary, $html) {
    return array('title' => $title, 'page' => $page, 'summary' => $summary, 'html' => $html);
};

$ch = array();

// ============================================================================ 1
$ch[] = array('title' => 'Getting started', 'sections' => array(

'intro' => $S('About this system', null, 'What G-SMaRT BIR does, and what it does not do.', <<<'H'
<p>G-SMaRT BIR Integration System prepares the electronic attachments that the Bureau of Internal Revenue asks taxpayers to submit with their returns: alphalists, quarterly and monthly alphabetical lists of payees, summary lists of sales and purchases, and the 2307, 2306 and 2316 certificates.</p>
<p>You keep your transactions here, either by typing them, pasting them into a grid, importing an Excel workbook or receiving them from another system. When a period is complete, the system writes the DAT file the BIR validation module expects, checks it, and keeps a record of every step until the file is filed.</p>
<h3>What it does for you</h3>
<ul>
<li>Keeps sales, purchases, importations, taxes you withheld, taxes withheld from you, and employee compensation for each client and branch.</li>
<li>Writes DAT files for SLS, SLP, SLI, 1601-EQ, 1601-FQ, 1600-VT, 1600-PT, 1604-C, 1604-E, 1604-F, SAWT and the SRS.</li>
<li>Checks every file twice before you see it: once for data quality and once by reading the file back.</li>
<li>Tracks the official BIR validator result, a second person's review, approval, and the filing reference.</li>
<li>Issues 2307, 2306 and 2316 certificates from the same records, so certificates and alphalists always agree.</li>
<li>Keeps an audit trail that shows who did what, and detects any tampering.</li>
</ul>
<h3>What it does not do</h3>
<ul>
<li>It does not submit anything to the BIR. You validate files with the BIR's own validation module and send them through eSubmission, eFPS or eBIRForms as usual.</li>
<li>It never marks a file as accepted by the BIR on its own. Only the validator's report, uploaded by a person and reviewed by another, can do that.</li>
<li>It does not compute your tax returns. It prepares the attachments and certificates that go with them.</li>
</ul>
<div class="mnote"><strong>Before using any file for a real filing,</strong> read <a href="{sec:three_levels}">How validation works</a>. Most file layouts are marked as drafts or pending verification until your own validator run confirms them.</div>
H
),

'signing_in' => $S('Signing in and your account', 'settings', 'Sign in, recover your password and turn on two-step sign-in.', <<<'H'
<figure><img src="{img:login}" alt="Sign-in page"><figcaption>The sign-in page.</figcaption></figure>
<h3>Sign in</h3>
<ol class="steps">
<li>Open the address your administrator gave you, for example <code>http://localhost/gsmart-bir/</code>.</li>
<li>Enter your email and password, then click <b>Sign in</b>.</li>
<li>If two-step sign-in is on for your account, enter the 6-digit code from your authenticator app.</li>
</ol>
<p>The first time you sign in with an account an administrator created, you are asked to set a new password before you can continue.</p>
<h3>Forgot your password</h3>
<p>Click <b>Forgot your password?</b> on the sign-in page and enter your email. A reset link is sent to you. If your office has not set up email sending, the message waits in the outbox on the Platform settings page, and an administrator can copy the link for you.</p>
<h3>Passwords and lockouts</h3>
<ul>
<li>Passwords need at least 10 characters with letters and numbers.</li>
<li>After 5 wrong attempts for one email, or 25 from one computer, sign-in is blocked for 15 minutes.</li>
<li>You are signed out after a period without activity (30 minutes unless your administrator changed it).</li>
</ul>
<h3>Account and security page</h3>
<p>Open your name at the top right and choose <b>Account and security</b>. From there you can:</p>
<ul>
<li><b>Profile</b>: change your display name.</li>
<li><b>Password</b>: change your password. You need the current one.</li>
<li><b>Two-step sign-in</b>: click <b>Set up two-step sign-in</b>, scan the QR code with Google Authenticator, Microsoft Authenticator or a similar app, enter the code it shows and click <b>Confirm and turn on</b>.</li>
<li><b>Recent sign-ins</b>: check the last sign-ins to your account, including failed ones.</li>
</ul>
<figure><img src="{img:settings}" alt="Account and security page"><figcaption>Turning on two-step sign-in.</figcaption></figure>
<div class="mnote">Anyone who can approve files, manage members or change reference data should use two-step sign-in. An administrator can require it for those roles.</div>
H
),

'navigation' => $S('Finding your way around', 'dashboard', 'The top bar, the folder tabs, the client switcher, search, themes and the phone layout.', <<<'H'
<figure><img src="{img:dashboard}" alt="Dashboard with the top bar and folder tabs"><figcaption>The top bar, the folder tabs and the dashboard.</figcaption></figure>
<h3>The top bar</h3>
<ul>
<li><b>Client switcher</b>: the box beside the logo shows the client you are working on. Everything you see and create belongs to that client. Choose another client from the list to switch. The boxes next to it show the client's TIN and head-office branch code.</li>
<li><b>Search</b>: type a TIN (with or without dashes), a name, an invoice number or a DAT filename. Results come from the active client only.</li>
<li><b>Theme</b>: the sun or moon button switches between light and dark mode. Your choice is remembered on this computer.</li>
<li><b>Notifications</b>: the bell shows approval requests and decisions that concern you. A number means unread items.</li>
<li><b>Your name</b>: opens Account and security, Switch organization (if you belong to more than one), the User's Manual and Sign out.</li>
</ul>
<h3>The folder tabs</h3>
<p>The tabs group the pages: <b>Overview</b>, <b>Records</b>, <b>Imports</b>, <b>Filing</b>, <b>Validation</b>, <b>Certificates</b> and <b>Admin</b>. Pointing at or clicking a tab opens its pages. You only see pages your role allows. The number on <b>Validation</b> counts requests waiting for you.</p>
<h3>The page bar and Help</h3>
<p>Every page starts with its title, a line saying which client it shows, its main buttons on the right, and a <b>Help</b> button. Help opens a short guide to that page on the right side of the screen, with a link to the full section of this manual.</p>
<figure><img src="{img:help_drawer}" alt="Help panel open beside a page"><figcaption>The Help panel for Taxes we withheld.</figcaption></figure>
<h3>On a phone</h3>
<p>On a narrow screen the tabs move to a bar at the bottom: <b>Home</b>, <b>Records</b>, <b>Filing</b>, <b>Validate</b> and <b>More</b>. Tables turn into stacked cards, one per row, with the column name beside each value.</p>
<figure class="narrow"><img src="{img:mobile}" alt="Dashboard on a phone"><figcaption>The dashboard on a phone.</figcaption></figure>
<figure><img src="{img:dark}" alt="Dashboard in dark mode"><figcaption>Dark mode.</figcaption></figure>
H
),

'dashboard' => $S('Dashboard', 'dashboard', 'The filing calendar, quarter totals and your work queue.', <<<'H'
<p>The dashboard summarises the active client.</p>
<ul>
<li><b>Filing calendar</b>: one row per file family (Sales list, Purchases list, QAP 1601-EQ, MAP, SAWT and so on) and one column per month. A filled mark means a file exists; its color says where it stands: validated or approved, waiting for BIR validation, or failed a check. Point at a mark to read its status. Use <b>Previous year</b> and <b>Next year</b> to move.</li>
<li><b>Quarter totals</b>: sales, output VAT, input VAT, taxes you withheld and taxes withheld on you for the current quarter. A month that is unexpectedly low usually means missing records.</li>
<li><b>Taxes withheld per month</b>: a bar for each month, to spot gaps before you generate a QAP.</li>
<li><b>Work queue</b>: approvals waiting for your decision, files waiting for BIR validation, evidence waiting for review and imports with errors. Click an item to open it.</li>
<li><b>Recent activity</b>: the latest entries in the audit trail for this client.</li>
</ul>
<p>Due dates appear only after an administrator adds deadline rules with their BIR source under <a href="{sec:reference}">RDO codes and deadlines</a>. The system never guesses a due date.</p>
<p>The <b>Import a spreadsheet</b> and <b>Generate a file</b> buttons at the top are shortcuts to the two most common tasks.</p>
H
),

'roles' => $S('Roles and what each can do', 'users', 'The nine roles and the work each one is meant for.', <<<'H'
<p>Every member of an organization has one role. A role is a fixed set of permissions; you cannot give one person extra permissions outside their role. Members can also be limited to some clients or branches.</p>
<table class="mtable">
<thead><tr><th>Role</th><th>Meant for</th><th>Can</th></tr></thead>
<tbody>
<tr><td>Firm owner</td><td>The head of the firm or company</td><td>Everything in the organization, including members, backups and approvals</td></tr>
<tr><td>Firm administrator</td><td>Office manager</td><td>Same as the owner; used to share administration</td></tr>
<tr><td>Client administrator</td><td>A client's own accountant</td><td>Manage assigned clients, records, imports, files, certificates, locks and backups</td></tr>
<tr><td>Accountant / preparer</td><td>Staff who prepare filings</td><td>Records, imports, generating files, recording validator results, issuing certificates, requesting approvals</td></tr>
<tr><td>Encoder</td><td>Data entry staff</td><td>Add and edit records and stage imports; cannot commit imports or generate files</td></tr>
<tr><td>Reviewer / approver</td><td>Senior accountant or partner</td><td>Review validator evidence, approve or reject requests, lock and unlock periods, approve tax codes and layouts</td></tr>
<tr><td>Authorized signatory</td><td>The person whose name signs certificates</td><td>View records, issue and sign certificates</td></tr>
<tr><td>Client read-only / auditor</td><td>External auditor, client staff</td><td>Read records, files and the audit trail; download files</td></tr>
<tr><td>Support (time-limited)</td><td>Outside support</td><td>Read-only access that expires</td></tr>
</tbody></table>
<h3>Maker-checker</h3>
<p>Some actions always need two people: the one who asks and a different one who approves. These are releasing a file for filing, unlocking a closed month, approving a tax code or file layout, voiding a certificate, accepting validator evidence and approving a signature image. Nobody can approve their own request, and nobody can change their own role.</p>
H
),

'quickstart' => $S('Your first filing, step by step', 'generate', 'From an empty client to an approved QAP in ten steps.', <<<'H'
<p>This walk-through prepares a 1601-EQ QAP file. Other forms follow the same path.</p>
<ol class="steps">
<li><b>Add the client.</b> Admin, Clients and branches, <b>Add client</b>. Enter the TIN, name, RDO code and address. The head office (branch 0000) is created for you. See <a href="{sec:client_form}">Client details</a>.</li>
<li><b>Check the tax codes.</b> Admin, Tax codes (ATC). Confirm the rates of the codes you use against the ATC table installed with your BIR validator, and have a reviewer approve them. See <a href="{sec:atc}">Tax codes</a>.</li>
<li><b>Add payees.</b> Records, Customers, suppliers, payees. Or let the import add them for you.</li>
<li><b>Record payments.</b> Records, Taxes we withheld, one entry per payment, or import a workbook. See <a href="{sec:withholding}">Taxes we withheld</a> and <a href="{sec:imports}">Import a spreadsheet</a>.</li>
<li><b>Preview.</b> Filing, Generate a file. Choose QAP for BIR Form 1601-EQ, Schedule 1, the branch, year and month, then <b>Preview rows and checks</b>. Fix any errors it lists.</li>
<li><b>Generate.</b> Click <b>Generate file</b>. Repeat for each month of the quarter: the QAP is one file per month.</li>
<li><b>Validate with the BIR module.</b> On the file page click <b>Download DAT</b> and run it through the BIR validation module on a Windows PC. See <a href="{sec:guide_validator}">Running the official BIR validator</a>.</li>
<li><b>Record the result.</b> Back on the file page, upload the .TXT report under Official validation and click <b>Record validator result</b>.</li>
<li><b>Review and approve.</b> A reviewer accepts the evidence. You click <b>Request approval</b>; the reviewer approves it under Validation, Approvals. The months are locked.</li>
<li><b>File and record.</b> Submit the file through the BIR channel, then come back and click <b>Record submission</b> with the reference number and acknowledgment. Issue the 2307 certificates from Certificates.</li>
</ol>
H
),
));

// ============================================================================ 2
$ch[] = array('title' => 'Setting up', 'sections' => array(

'orgs' => $S('Organizations', 'orgs', 'The workspace that owns clients, members and records.', <<<'H'
<p>An organization is a separate workspace. An accounting firm has one organization with many clients. A company filing for itself has one organization with one client (itself) and its branches. Data never crosses between organizations.</p>
<ul>
<li>When you register, you create your organization and become its owner.</li>
<li>If you belong to more than one organization, open your name at the top right, choose <b>Switch organization</b> and click <b>Switch</b> beside the one you want.</li>
<li>Click <b>New organization</b> to create another one. You become its owner.</li>
</ul>
<div class="mnote">The Platform Administrator account manages the installation and does not belong to any organization. To add clients with it, create or switch into an organization first.</div>
H
),

'clients' => $S('Clients and branches', 'clients', 'Every taxpayer you prepare reports for, and its branches.', <<<'H'
<figure><img src="{img:clients}" alt="Clients and branches list"><figcaption>The client list. The highlighted row is the active client.</figcaption></figure>
<p>A client is one taxpayer with its own TIN. Each client has branches; the head office is always branch <b>0000</b>. Records, files and certificates belong to a client and usually to a branch.</p>
<ul>
<li><b>Work on</b> makes that client the active one, the same as choosing it in the top bar.</li>
<li><b>Details</b> opens the client's settings, branches and signatories.</li>
<li><b>Files</b> counts current files; <b>Awaiting BIR validation</b> counts files still waiting for a validator result.</li>
</ul>
H
),

'client_form' => $S('Client details', 'clients', 'TIN, names, RDO, classification, branches, signatories and archiving.', <<<'H'
<figure><img src="{img:client_form}" alt="Client details form"><figcaption>Client details, with tabs for branches and signatories.</figcaption></figure>
<h3>Details</h3>
<ul>
<li><b>Taxpayer type</b>: non-individual clients use the registered name; individuals use last, first and middle names.</li>
<li><b>TIN</b>: nine digits. Dashes are optional. The branch code is set on the branch, not here.</li>
<li><b>RDO code</b>: three digits (081) or digits and a letter (21B). The list suggests codes as you type. Branches can have their own RDO.</li>
<li><b>Classification</b>: regular, top withholding agent, large taxpayer or government. This affects which ATCs make sense.</li>
<li><b>VAT status</b> and <b>Accounting period</b>: calendar year, or fiscal year with the month it ends.</li>
</ul>
<h3>Branches</h3>
<p>Open the <b>Branches</b> tab to add a branch with its 4-digit code (5 digits are also accepted), name, RDO and address. Each branch can be chosen on records, files and certificates.</p>
<h3>Signatories</h3>
<p>Open the <b>Signatories</b> tab to add the people whose names print on certificates: full name, title and TIN. You can upload a signature image (PNG or JPG). A signature image can only be used after a different person with approval rights approves it. Mark one signatory as the default.</p>
<h3>Archiving a client</h3>
<p><b>Archive client</b> keeps every record, file, certificate and audit event readable, but blocks new records, imports, files and certificates. Use it when an engagement ends. Click <b>Reactivate client</b> to undo it.</p>
<div class="mwarn">Data quality blocks a file when the client or branch has no 3-digit RDO code, because the BIR layouts require one.</div>
H
),

'users' => $S('Members and roles', 'users', 'Invite people, set their role and limit them to clients.', <<<'H'
<figure><img src="{img:users}" alt="Members and roles"><figcaption>Members, their roles, client access and security.</figcaption></figure>
<ol class="steps">
<li>Open Admin, Members and roles.</li>
<li>Under <b>Invite a member</b>, enter the name and email and choose a role. See <a href="{sec:roles}">Roles</a>.</li>
<li>Under <b>Clients</b>, keep <b>All clients</b>, or untick it and choose the clients this person may see.</li>
<li>Click <b>Send invitation</b>. New people receive a link to set their password; existing users get access at once.</li>
</ol>
<p>Click <b>Edit</b> on a member to change the role, client access or status. You cannot change your own role. The Security column shows whether the person uses two-step sign-in.</p>
<h3>Support access</h3>
<p>To let an outside helper look at your data for a while, use <b>Time-limited support access</b>: choose the person, the number of hours and the reason, then <b>Grant access</b>. <b>Revoke now</b> ends it early.</p>
H
),

'atc' => $S('Tax codes (ATC)', 'atc', 'The ATC registry: rates, effective dates and approval.', <<<'H'
<figure><img src="{img:atc}" alt="Tax codes registry"><figcaption>The ATC registry with status and source of each code.</figcaption></figure>
<p>Every withholding entry uses an ATC from this registry. Each code has a kind (EWT, FWT, VAT or percentage tax), a payee type (individual or corporate), a rate and an effective date.</p>
<ul>
<li>The seeded codes come from the 1601-EQ January 2019 ATC annex. They start as <b>pending review</b>.</li>
<li>Check each code you use against the ATC table installed with your BIR validator (and its latest patch), then have a reviewer approve it.</li>
<li>A file that uses a pending code is always labelled an unvalidated draft.</li>
</ul>
<h3>Changing a rate</h3>
<p>Rates are never edited in place. Under <b>Propose a code or new version</b>, enter the code, kind, payee type, rate, the date it takes effect and the BIR issuance that supports it, then <b>Submit for approval</b>. Payments before that date keep the old rate; payments on or after it use the new one.</p>
H
),

'specs' => $S('File layouts', 'specs', 'The registry that defines every byte of every DAT file.', <<<'H'
<figure><img src="{img:specs}" alt="File layouts list"><figcaption>File layouts with their evidence level and status.</figcaption></figure>
<p>The system never hard-codes a BIR format. Every field of every DAT comes from a layout here, and each field records where its definition came from:</p>
<ul>
<li><b>Official annex</b>: copied from a BIR annex table.</li>
<li><b>Validator-passed sample</b>: matches a file the official validator accepted.</li>
<li><b>Two or more sources agree</b>, or <b>Single secondary source</b>: from published generators, not yet confirmed.</li>
<li><b>Unsourced draft</b>: a placeholder so the module can be used; its files are always drafts.</li>
</ul>
<p>Layout status is <b>Draft</b>, <b>Pending verification</b>, <b>Approved</b> or <b>Retired</b>. Only approved layouts can produce submission-ready files. No layout ships approved: approval needs a validator run on your own installation.</p>
<div class="mnote">See <a href="{sec:spec_view}">Layout details</a> for how to correct a layout and get it approved.</div>
H
),

'spec_view' => $S('Layout details', 'specs', 'Fields, sources and how to correct and approve a layout.', <<<'H'
<figure><img src="{img:spec_view}" alt="Layout details"><figcaption>The 1604-C Schedule 1 layout: header, detail and control records.</figcaption></figure>
<p>A layout has records (header, details, control), and each record has fields in order. For each field you see its label, source, type, width, quoting and evidence.</p>
<h3>Correcting a layout</h3>
<ol class="steps">
<li>Click <b>Make a draft copy</b>. Approved and pending layouts are never edited directly, so files already made keep the layout they were made with.</li>
<li>In the draft, click a field to edit it, or add fields. <b>Save field</b>.</li>
<li>Set the effective dates and filename pattern under the layout settings and click <b>Save settings</b>.</li>
<li>Generate a file with the draft, validate it with the BIR module and record the result.</li>
<li>Click <b>Request approval</b> and cite the validator evidence. A reviewer approves it.</li>
</ol>
<h3>Source expressions</h3>
<table class="mtable"><tbody>
<tr><td><code>lit:H1604C</code></td><td>A fixed value</td></tr>
<tr><td><code>agent.tin</code>, <code>agent.branch</code>, <code>agent.rdo</code></td><td>The filing client or branch</td></tr>
<tr><td><code>row.income</code>, <code>row.atc</code></td><td>A value from the detail row</td></tr>
<tr><td><code>sum.income</code>, <code>sum.tax</code></td><td>Totals for control records</td></tr>
<tr><td><code>period.mm_yyyy</code>, <code>period.mm_dd_yyyy_end</code></td><td>The return period</td></tr>
<tr><td><code>seq</code>, <code>blank</code></td><td>Running number; an empty field</td></tr>
</tbody></table>
H
),

'reference' => $S('RDO codes and deadlines', 'reference', 'The RDO list and the deadline rules shown on the dashboard.', <<<'H'
<figure><img src="{img:reference}" alt="RDO codes"><figcaption>RDO codes, pre-loaded from a published list.</figcaption></figure>
<h3>RDO codes</h3>
<p>The list is pre-loaded so client and branch forms can suggest codes. It comes from a published secondary list, so check it against the BIR website. To update it, add or rename one code at a time, or import a CSV with two columns: code and name. Codes are three digits (081) or digits and a letter (21B). Fields accept any valid code even if it is not listed.</p>
<h3>Filing deadline rules</h3>
<p>Open the <b>Filing deadline rules</b> tab to add rules for the dashboard calendar. A rule names the form, how many months after the period it falls, the due day of that month, and the BIR issuance that sets it. Nothing is pre-filled, because deadlines change by issuance and by filing group. Add only rules you can cite.</p>
H
),

'system' => $S('Platform settings', 'system', 'Settings that apply to the whole installation.', <<<'H'
<p>Only the Platform Administrator sees this page.</p>
<ul>
<li><b>Allow self-service registration</b>: lets new firms or companies create their own organization from the sign-in page.</li>
<li><b>Require two-step sign-in for privileged roles</b>: owners, administrators and reviewers must set up two-step sign-in before they can continue.</li>
<li><b>Email</b>: <i>Outbox only</i> keeps messages in the outbox on this page; <i>Send with PHP mail()</i> also sends them, once the server can send mail.</li>
<li><b>Sign out after idle minutes</b> and the <b>Upload limit (MB)</b>.</li>
<li><b>Default SRS ATCs</b>: which ATCs count as regular-supplier purchases for the SRS.</li>
<li><b>Current BIR validator</b> and <b>Current ATC patch</b>: the module version and patch your office uses. They are shown on every file page.</li>
<li><b>Mail outbox</b>: every message the system has written, with its link, for offices without email sending.</li>
</ul>
H
),
));

// ============================================================================ 3
$ch[] = array('title' => 'Keeping records', 'sections' => array(

'directory' => $S('Customers, suppliers, payees', 'directory', 'One directory of counterparties for every kind of record.', <<<'H'
<figure><img src="{img:directory}" alt="Directory"><figcaption>The directory with roles and default ATCs.</figcaption></figure>
<p>Every record points to a counterparty in the directory: a customer, a supplier, a payee you withhold from, or a payor who withholds from you. One entry can have several roles.</p>
<ul>
<li>TIN plus branch code must be unique for each client. Foreign suppliers without a TIN are allowed; give their foreign address.</li>
<li>Individuals need last and first names; non-individuals need the registered name.</li>
<li>A <b>default ATC</b> fills in automatically when you record a payment to that payee.</li>
<li>Names are stored as you type them. When writing a DAT file, the system converts them to capitals and reports every other change (for example Ñ to N) in the data quality list.</li>
</ul>
<p>Click <b>Add entry</b> to add one, or <b>Import directory</b> to load a list from a workbook. Imports and quick entries also add counterparties automatically.</p>
H
),

'sales' => $S('Sales', 'sales', 'Invoices and receipts issued, for the Summary List of Sales.', <<<'H'
<p>Record each sales invoice or official receipt: branch, customer, invoice number and date, and the amounts.</p>
<ul>
<li><b>Exempt</b>, <b>zero-rated</b> and <b>taxable</b> amounts are entered separately. Taxable sales are net of VAT.</li>
<li><b>Output VAT</b> is compared with 12% of taxable sales. A difference is a warning, not a block, so you can match the invoice exactly.</li>
<li>The Summary List of Sales covers all branches of the TIN for the month and groups the rows per customer TIN.</li>
</ul>
<p>See <a href="{sec:record_form}">Adding, editing and voiding records</a> for the parts common to every record page.</p>
H
),

'purchases' => $S('Purchases', 'purchases', 'Supplier invoices for the Summary List of Purchases.', <<<'H'
<p>Record each supplier invoice with the amount split into <b>exempt</b>, <b>zero-rated</b>, <b>services</b>, <b>capital goods</b> and <b>other goods</b>, plus the input VAT.</p>
<ul>
<li><b>Non-creditable VAT</b> is VAT you cannot claim. It reduces creditable input tax in the summary list header.</li>
<li>Purchases from non-VAT suppliers go under exempt.</li>
<li>Purchases from suppliers paid with the ATCs listed under Default SRS ATCs on Platform settings are used for the Semestral List of Regular Suppliers.</li>
</ul>
H
),

'importations' => $S('Importations', 'importations', 'Import entries for the Summary List of Importations.', <<<'H'
<p>One record per import entry: entry number, importation and assessment dates, country of origin, landed cost, other charges, exempt and taxable amounts, VAT paid, and the official receipt and payment date. The Summary List of Importations writes each entry separately, without grouping.</p>
H
),

'withholding' => $S('Taxes we withheld', 'withholding', 'Payments where the client withheld tax; the source of QAPs, MAPs, annual lists and 2307s.', <<<'H'
<figure><img src="{img:withholding}" alt="Taxes we withheld list"><figcaption>Withholding entries with filters by month, branch, kind and status.</figcaption></figure>
<p>Use this page when the client paid someone and withheld tax. These records feed the QAP (1601-EQ and 1601-FQ), the MAP (1600-VT and 1600-PT), the 1604-E and 1604-F annual schedules, and the 2307 and 2306 certificates.</p>
<figure><img src="{img:record_form}" alt="Add withholding entry form"><figcaption>Adding a withholding entry. The rate and tax fill from the ATC.</figcaption></figure>
<ol class="steps">
<li>Click <b>Add withholding entry</b>.</li>
<li>Choose the <b>branch</b> and the <b>payee</b>. If the payee is new, click <b>Add a new payee</b>.</li>
<li>Choose the <b>kind of withholding</b>. The ATC list then shows only codes of that kind that are in force on the payment date.</li>
<li>Choose the <b>ATC</b>, enter the <b>payment date</b> and the <b>income payment</b>.</li>
<li>The <b>tax rate</b> fills from the registry and <b>tax withheld</b> is computed to the centavo. Click <b>Fill from rate</b> to recompute after a change.</li>
<li>Choose the <b>schedule placement</b>. Most payments are placement A (Schedule 1 of the QAP).</li>
<li>Click <b>Save</b>, or <b>Save and add another</b> to keep the branch and date for the next entry.</li>
</ol>
<div class="mnote"><b>Different rate or amount?</b> You can keep a rate or tax that differs from the registry, but you must write the reason. It is shown to reviewers.</div>
<div class="mwarn">Individual ATCs (starting with WI) need an individual payee, and corporate ATCs (WC) a non-individual payee. The form refuses a mismatch.</div>
H
),

'withheld_on_us' => $S('Taxes withheld on us', 'withheld_on_us', '2307 certificates received from customers, for the SAWT.', <<<'H'
<p>Use this page when the client was paid and the payor withheld tax, usually shown on a 2307 the payor gave you. These records build the SAWT that goes with the client's own income tax or business tax return.</p>
<ul>
<li>Record one entry per payor, ATC and month as shown on the certificate.</li>
<li>Keep the certificate number in the reference field so the reconciliation report can match them.</li>
<li>The ATC must match the client: individual ATCs for individual clients, corporate ATCs for non-individuals.</li>
</ul>
H
),

'employees' => $S('Employees', 'employees', 'The employee list used by compensation records and Form 2316.', <<<'H'
<p>Each employee has an employee number, TIN (9 digits, branch usually 0000), names, region, nationality and employment status. Employees belong to a branch. Click <b>Compensation records</b> to see the employee's yearly records.</p>
H
),

'compensation' => $S('Compensation', 'compensation', 'Yearly compensation per employee, for the 1604-C and Form 2316.', <<<'H'
<figure><img src="{img:compensation}" alt="Compensation list"><figcaption>TY2025 compensation with a totals check on each row.</figcaption></figure>
<p>Keep one record per employee per year and schedule. Schedule 1 is for employees other than minimum wage earners; Schedule 2 is for minimum wage earners.</p>
<ul>
<li>Enter present-employer amounts: gross compensation, the non-taxable parts (13th month and other benefits up to the ceiling, de minimis, SSS/PhilHealth/Pag-IBIG contributions, other) and the taxable parts.</li>
<li>If the employee had a previous employer during the year, fill in the previous-employer group too.</li>
<li>Enter tax due, tax withheld January to November and in December, and any refund or adjustment.</li>
<li>Click <b>Compute totals</b> to fill the totals from their parts before saving.</li>
</ul>
<p>The <b>Check</b> column shows whether each record adds up. A record that does not add up to the centavo blocks the 1604-C, because the BIR validator rejects inconsistent rows. Use <b>Import compensation</b> to load a whole year from your payroll system, and <b>Generate 1604-C</b> when the year is complete.</p>
H
),

'grid' => $S('Grid entry', 'grid', 'Spreadsheet-style entry for many records at once.', <<<'H'
<figure><img src="{img:grid}" alt="Grid entry"><figcaption>Grid entry for withholding.</figcaption></figure>
<p>Choose what to enter (taxes we withheld, sales or purchases) at the top right. Each row is one record.</p>
<ul>
<li>Type a TIN or name in the party column and pick from the list.</li>
<li><kbd>Enter</kbd> moves down, <kbd>Tab</kbd> moves right, <kbd>Ctrl</kbd>+<kbd>Enter</kbd> saves all rows.</li>
<li>Leave the rate and tax blank to compute them from the ATC.</li>
<li>Click <b>Add row</b> for more rows and <b>Save rows</b> to save.</li>
</ul>
<p>Saved rows turn green. Rows with errors stay on screen with the message beside them, so you can fix them and save again. Blank rows are ignored.</p>
H
),

'periods' => $S('Period locks', 'periods', 'Closing months so nobody changes filed figures.', <<<'H'
<figure><img src="{img:periods}" alt="Period locks"><figcaption>Locked months by scope, with the person and reason.</figcaption></figure>
<p>A locked month cannot be edited, imported into or voided. Locks apply per scope: sales, purchases, withholding, compensation, or all records.</p>
<ul>
<li>Approving a file locks the months it covers automatically.</li>
<li>A reviewer can lock a month by hand under <b>Lock a month</b>, with a reason.</li>
<li>To unlock, click <b>Request unlock</b> and give a reason. A second person must approve it under Approvals.</li>
</ul>
H
),

'record_form' => $S('Adding, editing and voiding records', 'withholding', 'What every record page has in common.', <<<'H'
<p>Sales, purchases, importations, taxes we withheld and taxes withheld on us all work the same way.</p>
<h3>Lists</h3>
<p>Filter by month, branch, kind, status or text, then click <b>Filter</b>. Click <b>Open</b> on a row to see or change it. Use <b>CSV</b> to download the filtered list.</p>
<h3>Editing</h3>
<p>Change the fields and save. You are asked for a reason. The previous version is kept as a revision, and the audit trail records who changed what. A record in a locked month cannot be edited.</p>
<h3>Voiding and restoring</h3>
<p>Records are never deleted. <b>Void</b> keeps the record, with your reason, but leaves it out of files and certificates. <b>Restore</b> brings it back. Both need the month to be unlocked.</p>
<h3>Duplicates</h3>
<p>The system remembers a fingerprint of every record. An import row identical to a record already saved is skipped, so loading the same workbook twice does not double your figures.</p>
H
),
));

// ============================================================================ 4
$ch[] = array('title' => 'Importing spreadsheets', 'sections' => array(

'templates' => $S('Template library', 'templates', 'Excel templates for every import type.', <<<'H'
<figure><img src="{img:templates}" alt="Template library"><figcaption>One template per import type, blank or with sample rows.</figcaption></figure>
<p>Each template is an .xlsx workbook with three sheets:</p>
<ul>
<li><b>Data</b>: a locked header row. Enter one record per row underneath.</li>
<li><b>Fields</b>: what each column means, whether it is required and its format.</li>
<li><b>About</b>: the template code and version, which the import uses to recognise it.</li>
</ul>
<p>Download <b>Blank</b> for real work and <b>With sample rows</b> to see the expected format. TIN columns are already formatted as text so leading zeros survive.</p>
H
),

'imports' => $S('Import a spreadsheet', 'imports', 'Upload a workbook or CSV, map its columns, check and commit.', <<<'H'
<figure><img src="{img:imports}" alt="Import a spreadsheet"><figcaption>Uploading a workbook. Recent imports are listed on the left.</figcaption></figure>
<ol class="steps">
<li>Choose <b>The file contains</b> (for example Taxes we withheld) and the <b>Default branch</b> for rows that do not name one.</li>
<li>Choose your .xlsx or .csv file and click <b>Upload and map columns</b>. Large files upload in pieces and resume if the connection drops.</li>
<li>Map the columns (see <a href="{sec:import_map}">Map columns</a>). A G-SMaRT template maps itself.</li>
<li>Click <b>Stage and check rows</b>. Rows are checked in batches; nothing touches your records yet.</li>
<li>Review the results and commit (see <a href="{sec:import_review}">Review an import</a>).</li>
</ol>
<div class="mwarn"><b>TINs stored as numbers.</b> Excel drops leading zeros and may show long numbers as 1.23E+08. Rows whose TIN looks damaged this way are rejected. Format TIN columns as Text before typing.</div>
<p>Legacy .xls files are not read. Open them in Excel and save as .xlsx first.</p>
H
),

'import_map' => $S('Map columns', 'imports', 'Tell the system which column holds which field.', <<<'H'
<p>The left side lists the system's fields; required ones are marked. For each, choose the column of your file that holds it. A preview shows the first rows as they will be read.</p>
<ul>
<li>Choose the <b>sheet</b> and the <b>header row</b> if your workbook has titles above the headers.</li>
<li>Choose the <b>date order</b> used in the file (month/day/year or day/month/year) when dates are typed with slashes.</li>
<li>Type a name under <b>Save this mapping as</b> to reuse it next time for files from the same source.</li>
<li><b>Reload preview</b> refreshes the sample; <b>Stage and check rows</b> continues.</li>
</ul>
H
),

'import_review' => $S('Review an import', 'imports', 'Valid rows, duplicates, errors, commit and roll back.', <<<'H'
<figure><img src="{img:import_review}" alt="Review import"><figcaption>A committed import with its row counts and the progress track.</figcaption></figure>
<p>The track at the top shows where the import stands: Uploaded, Mapped, Staged and checked, Committed. The counts show rows read, ready to commit, with errors, duplicates and committed.</p>
<h3>Committing</h3>
<ul>
<li>Choose <b>Commit everything</b> (allowed only when no row has errors) or <b>Commit the valid rows and leave the rest</b>, then click <b>Commit rows</b>.</li>
<li><b>Replace the period</b> first voids records imported earlier for the same months, for when you re-import a corrected month. It needs a reason and the replace permission.</li>
<li>Encoders can stage imports; a preparer commits them.</li>
</ul>
<h3>Fixing errors</h3>
<p>Each error row shows its messages. Click <b>Corrections workbook</b> to download the rows with errors and a message column, fix them in Excel and upload that file as a new import. <b>Change the mapping</b> goes back a step. <b>Discard import</b> drops an import that was never committed.</p>
<h3>Rolling back</h3>
<p><b>Roll back this import</b> voids every record it committed, with a reason, as long as the months are not locked.</p>
H
),
));

// ============================================================================ 5
$ch[] = array('title' => 'Generating BIR files', 'sections' => array(

'generate' => $S('Generate a file', 'generate', 'Preview the rows and checks, then write the DAT file.', <<<'H'
<figure><img src="{img:generate}" alt="Generate a file"><figcaption>Previewing September's QAP: rows, totals, data quality and a byte preview.</figcaption></figure>
<ol class="steps">
<li>Choose the <b>Form</b>, the <b>Schedule</b> and the <b>Branch</b>.</li>
<li>Choose the <b>Year</b> and the period: month, quarter, half or year, depending on the form. For SAWT, also choose the return it goes with (for example 1701Q).</li>
<li>Tick <b>Amended return</b> if this replaces a file already filed.</li>
<li>Click <b>Preview rows and checks</b>. The system builds the rows from saved records and runs the data quality checks. Nothing is stored yet.</li>
<li>Read the totals, the data quality list, the rows and the first lines of the file.</li>
<li>Click <b>Generate file</b>. The system freezes a copy of the rows, writes the DAT with the registered layout, reads it back and compares, and opens the file page.</li>
</ol>
<h3>Which period to choose</h3>
<ul>
<li><b>QAP</b> (1601-EQ, 1601-FQ): one file per month of the quarter.</li>
<li><b>SLS, SLP, SLI</b>: one file per month, covering all branches of the TIN.</li>
<li><b>MAP</b> (1600-VT, 1600-PT): monthly.</li>
<li><b>1604-C, 1604-E, 1604-F</b>: the year.</li>
<li><b>SAWT</b>: the period of the return it is attached to.</li>
</ul>
<h3>Errors and warnings</h3>
<p>Errors block a submission-ready file. Warnings are shown to reviewers but do not block. If you need to inspect a file despite errors, <b>Generate diagnostic draft</b> creates one labelled unvalidated. It can never become submission-ready.</p>
H
),

'file_view' => $S('File details', 'files', 'The three checks, the contents, validator evidence and the file history.', <<<'H'
<figure><img src="{img:file_view}" alt="File details"><figcaption>A file waiting for the official BIR validator.</figcaption></figure>
<p>The track under the title shows the file's progress: Prepared, Structure checked, Pending BIR validation, Officially validated, Approved, Submitted.</p>
<ul>
<li><b>Level 1: data quality</b> and <b>Level 2: DAT structure</b> show the result of the system's own checks. Click to see each message.</li>
<li><b>Level 3: official BIR validator</b> shows the validator result once recorded.</li>
<li><b>Contents</b>: <b>Raw bytes</b> shows the file exactly as written; <b>Fields</b> shows it split into labelled fields.</li>
<li><b>Download DAT</b> downloads the exact file. A draft download is labelled and logged as a draft.</li>
</ul>
<p>The right side guides the next step: recording the validator result, reviewing evidence, requesting approval, or recording the submission.</p>
<h3>Other actions</h3>
<ul>
<li><b>Re-process with current layout</b> writes a new file from the same frozen rows using the newest layout. The old file is superseded and its validation no longer applies.</li>
<li><b>Import rows into records</b> (on uploaded QAP, MAP and SAWT files) turns the file's rows into withholding records.</li>
</ul>
H
),

'files' => $S('File library', 'files', 'Every generated or uploaded DAT with its status.', <<<'H'
<figure><img src="{img:files}" alt="File library"><figcaption>The file library with status labels.</figcaption></figure>
<p>Filter by form, status, year, origin (generated or uploaded) or filename. Each row shows the period, branch, record count, tax total, status and who made it. Superseded files stay in the library for history; choose <b>Superseded</b> under Status to see them.</p>
<h3>Status labels</h3>
<table class="mtable"><tbody>
<tr><td>Structure failed</td><td>The file did not read back correctly. Fix the data or layout and generate again.</td></tr>
<tr><td>Pending BIR validation</td><td>Waiting for you to run the BIR validator and record the result.</td></tr>
<tr><td>Failed BIR validation</td><td>The validator found errors. Correct and generate a new file.</td></tr>
<tr><td>Officially validated</td><td>The validator report is recorded. Waiting for review and approval.</td></tr>
<tr><td>Approved</td><td>Released for filing; its months are locked.</td></tr>
<tr><td>Submitted</td><td>Filing evidence is recorded. The file is frozen.</td></tr>
<tr><td>Superseded</td><td>Replaced by a newer file for the same period.</td></tr>
</tbody></table>
<p>Before official validation, a file is also labelled <b>Submission-ready</b> (all checks passed, layout and ATCs approved) or <b>Unvalidated draft</b>.</p>
H
),

'dat_upload' => $S('Upload an existing DAT', 'dat_upload', 'Bring in DAT files made by other software.', <<<'H'
<figure><img src="{img:dat_upload}" alt="Upload an existing DAT"><figcaption>Uploading a DAT from another system.</figcaption></figure>
<ol class="steps">
<li>Choose the file and click <b>Upload and detect layout</b>. The layout is recognised from the filename and first record.</li>
<li>Check the parsed summary: TIN, form, period, record count and totals. A file for another TIN is refused.</li>
<li>Click <b>Add to library</b>.</li>
</ol>
<p>Uploaded files can be used as sources for the SRS and annual builder, validated and approved like generated files, and their rows can be imported into records.</p>
H
),

'consolidate' => $S('SRS and annual builder', 'consolidate', 'Build the 1604-E Schedule 4 and the SRS from monthly sources.', <<<'H'
<figure><img src="{img:consolidate}" alt="SRS and annual builder"><figcaption>Choosing a source for each month. Missing months are flagged.</figcaption></figure>
<ol class="steps">
<li>Choose what to build, the branch and the year, and click <b>Load months</b>.</li>
<li>For each month choose <b>Saved records</b> or a stored QAP file. The grid flags missing months, months with more than one file, branch mismatches and amended files.</li>
<li>Click <b>Preview consolidated rows</b>, check the totals, then <b>Generate consolidated file</b>.</li>
</ol>
<div class="mwarn">SRS: a secondary source reports that RMC 122-2019 ended SRS submission. Confirm the current rule before relying on this output.</div>
H
),

'reconcile' => $S('Reconciliation', 'reconcile', 'Checks that figures agree before you file.', <<<'H'
<figure><img src="{img:reconcile}" alt="Reconciliation"><figcaption>Certificates compared with QAP totals.</figcaption></figure>
<p>Choose a report, the year and quarter, and click <b>Run</b>.</p>
<ul>
<li><b>Certificates vs QAP</b>: 2307 totals per payee against the QAP for the quarter.</li>
<li><b>SAWT vs certificates received</b>: taxes withheld on us against certificates received.</li>
<li><b>RELIEF vs records</b>: summary list totals against saved sales and purchases.</li>
<li><b>Annual roll-forward</b>: the annual list against the monthly files.</li>
<li><b>Exceptions</b>: missing TINs, one TIN with different names, zero or negative amounts, and edits made after approval.</li>
</ul>
H
),

'filings' => $S('Filing records', 'filings', 'Proof of when and how each file was submitted.', <<<'H'
<p>After a file is approved, submit it through the BIR channel. Then open the file and click <b>Record submission</b>: choose the channel (eSubmission, eFPS, eBIRForms, eAFS or manual), the date, the reference number, and attach the acknowledgment email or screenshot. The file becomes <b>Submitted</b> and can no longer change.</p>
<p>This page lists every recorded submission. The system does not send anything to the BIR; it keeps your evidence.</p>
H
),
));

// ============================================================================ 6
$ch[] = array('title' => 'Validation and approval', 'sections' => array(

'three_levels' => $S('How validation works', null, 'The three separate levels and what each one proves.', <<<'H'
<p>Every file is checked at three levels. They are always shown separately, because only the third one is the BIR's.</p>
<table class="mtable">
<thead><tr><th>Level</th><th>Who runs it</th><th>What it checks</th></tr></thead>
<tbody>
<tr><td>1. Data quality</td><td>This system, before writing</td><td>Required fields, TIN formats, ATCs in force and approved, payee types, arithmetic, names that had to be changed</td></tr>
<tr><td>2. DAT structure</td><td>This system, after writing</td><td>Reads the file back independently: record counts, field widths, control totals against details, filename, line endings and encoding</td></tr>
<tr><td>3. Official BIR validation</td><td>You, with the BIR's validation module</td><td>Whatever the BIR checks. Proven only by the module's own report</td></tr>
</tbody></table>
<p>Passing levels 1 and 2 means the file is internally consistent. It does not mean the BIR will accept it. A file reaches <b>Officially validated</b> only when a person uploads the validator's report and a different person accepts it.</p>
<h3>Submission-ready or unvalidated draft</h3>
<p>Before level 3, a file is <b>submission-ready</b> only if levels 1 and 2 pass with no errors, its layout is approved, and every ATC it uses is approved. Otherwise it is an <b>unvalidated draft</b>. Drafts can be downloaded by people with the draft permission, and every draft download is logged as such.</p>
H
),

'guide_validator' => $S('Running the official BIR validator', 'files', 'Step-by-step: from download to recorded evidence.', <<<'H'
<ol class="steps">
<li>On a Windows PC, install the current Alphalist Data Entry and Validation Module from the BIR website, plus the latest ATC patch. Note the version and patch date.</li>
<li>On the file page, click <b>Download DAT</b>. Do not open and save the file in Excel or Notepad: that can change bytes.</li>
<li>Open the validation module, choose the form that matches the file and validate it.</li>
<li>Keep the .TXT report the module writes. If it only shows a message on screen, take a screenshot.</li>
<li>Back on the file page, under <b>Official validation</b>, enter the validator version, the ATC patch date and the date you ran it.</li>
<li>Choose the report file. For a screenshot or PDF, also choose the outcome; a .TXT report is read automatically.</li>
<li>Optionally add the DAT you validated. The system compares its SHA-256 with the generated file to prove they are the same bytes.</li>
<li>Click <b>Record validator result</b>.</li>
</ol>
<figure><img src="{img:file_view_validated}" alt="A file with accepted validator evidence"><figcaption>A 1604-C with an accepted validator report and matching bytes.</figcaption></figure>
<p>A report for a different TIN, form or period is refused. If the validator rejects the file, read the line numbers in its report, correct the data or the layout, generate a new file and validate again.</p>
H
),

'validation' => $S('Validation center', 'validation', 'All files grouped by where they stand.', <<<'H'
<figure><img src="{img:validation}" alt="Validation center"><figcaption>The validation center for one client.</figcaption></figure>
<p>The counters at the top show files waiting for BIR validation, officially validated, approved and submitted, and unvalidated drafts. Below, files are grouped:</p>
<ul>
<li><b>Structure problems</b>: files that did not read back correctly.</li>
<li><b>Waiting for official validation</b>: download, validate and upload the report.</li>
<li><b>Failed official validation</b>: correct and regenerate.</li>
<li><b>Evidence to review</b>: a reviewer accepts or rejects the uploaded report.</li>
<li><b>Ready for approval</b>: request release.</li>
</ul>
H
),

'evidence_review' => $S('Reviewing validator evidence', 'validation', 'The second person checks the report before anything is approved.', <<<'H'
<p>When someone records a validator result, reviewers are notified. On the file page, the reviewer:</p>
<ol class="steps">
<li>Opens the report (download link under the evidence) and checks that it names this TIN, form and period and says no errors were found.</li>
<li>Checks whether byte identity was proven, and the validator version and patch date.</li>
<li>Clicks <b>Accept</b> or <b>Reject</b> with a note.</li>
</ol>
<p>The person who uploaded the evidence cannot review it.</p>
H
),

'approvals' => $S('Approvals', 'approvals', 'Requests waiting for a second person.', <<<'H'
<figure><img src="{img:approvals}" alt="Approvals"><figcaption>Pending requests with their reason and requester.</figcaption></figure>
<p>Every request shows what is asked, the subject (click it to open), the client, who asked and why. Type a note and click <b>Approve</b> or <b>Reject</b>.</p>
<table class="mtable"><tbody>
<tr><td>Approve file for filing</td><td>Needs accepted official evidence. Locks the months the file covers.</td></tr>
<tr><td>Unlock a period</td><td>Reopens a locked month.</td></tr>
<tr><td>Approve a tax code</td><td>Puts a new ATC or rate in force. Needs the reference approval permission.</td></tr>
<tr><td>Approve a file layout</td><td>Allows the layout to produce submission-ready files. Needs the reference approval permission.</td></tr>
<tr><td>Void a certificate</td><td>Marks an issued certificate void.</td></tr>
</tbody></table>
<p>You cannot decide your own request. The requester is notified of the decision.</p>
H
),
));

// ============================================================================ 7
$ch[] = array('title' => 'Certificates', 'sections' => array(

'certificates' => $S('Certificate register', 'certificates', 'Every 2307, 2306 and 2316 issued.', <<<'H'
<figure><img src="{img:certificates}" alt="Certificate register"><figcaption>The certificate register.</figcaption></figure>
<p>Filter by form, year, status, number or TIN. Tick certificates and download <b>One combined PDF</b> for printing, or a <b>ZIP of individual PDFs</b> for emailing. Click <b>Open</b> to see one certificate.</p>
<p>Certificate numbers run per form and year, for example 2307-2026-00001. A reissued certificate keeps its number and gets the next revision.</p>
H
),

'cert_issue' => $S('Issue certificates', 'cert_issue', 'Issue 2307, 2306 or 2316 in batches from saved records.', <<<'H'
<figure><img src="{img:cert_issue}" alt="Issue certificates"><figcaption>Choosing payees with records for the quarter.</figcaption></figure>
<ol class="steps">
<li>Choose the form, branch, year and quarter (2307, 2306) or year (2316), and click <b>Load</b>.</li>
<li>Tick the payees or employees to issue for. Each row shows the income and tax that will print.</li>
<li>Choose the <b>signatory</b> and <b>paper</b> size (A4, Letter or Folio).</li>
<li>Tick <b>Apply the signatory's approved signature image</b> if allowed. Use the offsets to move the image for your printer.</li>
<li>Click <b>Issue selected</b>.</li>
</ol>
<p>Payees that already have a certificate for the period are skipped unless you tick <b>Issue even if a certificate already exists</b>.</p>
<div class="mshots"><figure><img src="{img:cert2307}" alt="Form 2307"><figcaption>Form 2307 as printed.</figcaption></figure><figure><img src="{img:cert2316}" alt="Form 2316"><figcaption>Form 2316 as printed.</figcaption></figure></div>
<div class="mnote">Certificates are drawn to the January 2018 (ENCS) layouts. Check each form's current revision on the BIR website before issuing.</div>
H
),

'cert_quick' => $S('Quick 2307 entry', 'cert_quick', 'One payee, monthly amounts, certificate in one step.', <<<'H'
<figure><img src="{img:cert_quick}" alt="Quick 2307 entry"><figcaption>Quick entry for one payee and quarter.</figcaption></figure>
<ol class="steps">
<li>Choose the year, quarter, branch and signatory.</li>
<li>Pick an existing payee, or type a new one's TIN, name and address.</li>
<li>For each ATC, type the income paid in each month of the quarter.</li>
<li>Click <b>Save records and issue 2307</b>.</li>
</ol>
<p>The amounts are first saved as withholding records, so the certificate and the QAP always agree.</p>
H
),

'cert_view' => $S('Void and reissue', 'certificates', 'Correcting a certificate already issued.', <<<'H'
<ul>
<li><b>Reissue</b> builds a new revision from the current records with the same number. Use it after correcting the underlying withholding records. The old revision is kept and marked reissued.</li>
<li><b>Request void</b> asks a second person to void the certificate, with a reason. Voided certificates print with a VOID mark and their records can be certified again.</li>
</ul>
H
),
));

// ============================================================================ 8
$ch[] = array('title' => 'Administration', 'sections' => array(

'audit' => $S('Audit trail', 'audit', 'Who did what, when, and proof that nothing was altered.', <<<'H'
<figure><img src="{img:audit}" alt="Audit trail"><figcaption>The audit trail with filters.</figcaption></figure>
<p>Every sign-in, record change, void, import, file generation, download, validator result, approval, certificate and backup is recorded with the person, time, IP address and, for edits, the values before and after.</p>
<p>Filter by scope, person, kind, dates or text. Click <b>Verify chain</b> to recompute the fingerprint of every event. Each event includes the fingerprint of the one before it, so changing or deleting any old event breaks the chain, and the check reports the first broken event.</p>
H
),

'backups' => $S('Backup and restore', 'backups', 'Encrypted client backups, test restores and database backups.', <<<'H'
<figure><img src="{img:backups}" alt="Backup and restore"><figcaption>Backing up the active client.</figcaption></figure>
<h3>Back up a client</h3>
<ol class="steps">
<li>Enter a passphrase of at least 12 characters, repeat it, and add a note.</li>
<li>Click <b>Create backup</b>, then download the file and keep it off the server.</li>
</ol>
<p>The backup holds the client's records, files, evidence and certificates, encrypted with your passphrase. The passphrase is not stored anywhere: if you lose it, the backup cannot be opened.</p>
<h3>Test and restore</h3>
<p><b>Test restore</b> opens a backup and checks it without changing anything. <b>Restore</b> replaces the client's data with the backup's contents. The audit trail is never rolled back, and the restore itself is recorded.</p>
<h3>Database backup</h3>
<p>The Platform Administrator can click <b>Create database backup</b> for a full SQL backup of the installation, optionally encrypted.</p>
H
),

'api_tokens' => $S('API tokens', 'api_tokens', 'Let a payroll, POS or accounting system send records.', <<<'H'
<figure><img src="{img:api_tokens}" alt="API tokens"><figcaption>Creating a token.</figcaption></figure>
<ol class="steps">
<li>Enter a name, for example "Payroll system".</li>
<li>Tick the scopes it needs: read records, create records, read files.</li>
<li>Limit it to one client, or allow any client you can access, and set an expiry.</li>
<li>Click <b>Create token</b> and copy the token at once. It is shown only one time.</li>
</ol>
<p>A token can never do more than its owner's role allows. <b>Revoke</b> stops it immediately. The endpoints are described in <code>docs/API.md</code>.</p>
H
),

'privacy' => $S('Data privacy checklist', null, 'What the system does and what your office must do.', <<<'H'
<p>The system holds TINs, income and tax figures for third parties and employee pay. Under the Data Privacy Act of 2012 your office is responsible for it.</p>
<h3>The system</h3>
<ul>
<li>Keeps each organization's data separate and limits each person to their clients.</li>
<li>Encrypts two-step sign-in secrets and backups, and logs every access to files.</li>
<li>Sends nothing to any outside service: no analytics, no AI service, no external fonts or scripts.</li>
</ul>
<h3>Your office</h3>
<ul>
<li>Run it over HTTPS and keep the storage folder outside the web folder.</li>
<li>Require two-step sign-in for privileged roles and remove access for people who leave.</li>
<li>Take encrypted backups regularly and keep them off the server.</li>
<li>Remove the demo organization and accounts before entering real data.</li>
<li>Register with the National Privacy Commission and appoint a Data Protection Officer if required, and set a retention policy.</li>
</ul>
H
),
));

// ============================================================================ 9
$ch[] = array('title' => 'Troubleshooting and reference', 'sections' => array(

'guide_faq' => $S('Frequently asked questions', null, 'Short answers to common questions.', <<<'H'
<p><b>Does the system file with the BIR?</b><br>No. It prepares files and keeps evidence. You submit through the BIR's channels and record the acknowledgment.</p>
<p><b>Why is my file an unvalidated draft?</b><br>Its layout is not yet approved, an ATC it uses is pending review, or the checks found errors. The file page says which.</p>
<p><b>Do Excel imports and typed records give the same file?</b><br>Yes. Imports save into the same records, and every file is written from saved records by the same writer.</p>
<p><b>Why are some layouts drafts?</b><br>The official field tables for some forms were not available as text. Correct them from the BIR annex under File layouts and confirm with the validator.</p>
<p><b>Can I delete a record?</b><br>No. Void it with a reason. It stays visible for audit but leaves files and certificates.</p>
<p><b>I changed a record after approval. What happens?</b><br>You cannot: approval locks the months. Ask for an unlock, correct the record, then generate an amended file.</p>
<p><b>Why does the dashboard show no due dates?</b><br>Deadline rules are added by an administrator with their BIR source. None are pre-filled.</p>
H
),

'troubleshooting' => $S('Troubleshooting', null, 'Messages you may see and what to do.', <<<'H'
<table class="mtable">
<thead><tr><th>Message or symptom</th><th>What to do</th></tr></thead>
<tbody>
<tr><td>Open an organization first</td><td>You are signed in as the Platform Administrator. Go to Organizations and switch into one, or create one.</td></tr>
<tr><td>TIN was stored as a number in scientific notation</td><td>Excel damaged the TIN. Format the TIN column as Text, retype the TINs and import again.</td></tr>
<tr><td>The period is locked</td><td>The month was closed or a file for it was approved. Request an unlock under Period locks.</td></tr>
<tr><td>An individual ATC is refused for a corporate payee</td><td>Use the WC code for companies and the WI code for individuals, or fix the payee type in the directory.</td></tr>
<tr><td>RDO code must be 3 digits</td><td>Set the RDO on the client or branch. Codes with a letter (21B) are accepted, but how they are written in a DAT is not confirmed.</td></tr>
<tr><td>The stored file does not match its recorded checksum</td><td>The file in storage was changed or replaced. Restore it from backup or re-process it.</td></tr>
<tr><td>A validator report for another TIN is refused</td><td>Upload the report for this file. Check that you validated the right DAT.</td></tr>
<tr><td>There are no records to write for this period</td><td>No posted records match the form, branch and period. Check the month and branch, and that records are not void.</td></tr>
<tr><td>Uploads fail or stop at large sizes</td><td>Ask the administrator to raise the upload limit on Platform settings and in PHP, and MySQL's max_allowed_packet to 16M.</td></tr>
<tr><td>The QR code for two-step sign-in does not show</td><td>Type the key shown under the QR code into your authenticator app instead.</td></tr>
</tbody></table>
H
),

'glossary' => $S('Glossary', null, 'Terms used in this manual and in BIR forms.', <<<'H'
<table class="mtable"><tbody>
<tr><td>ATC</td><td>Alphanumeric tax code. Identifies the kind of income payment and its withholding rate.</td></tr>
<tr><td>Alphalist</td><td>An alphabetical list of payees or employees attached to a return.</td></tr>
<tr><td>DAT file</td><td>The plain-text file format the BIR validation module reads.</td></tr>
<tr><td>EWT, FWT</td><td>Expanded (creditable) withholding tax and final withholding tax.</td></tr>
<tr><td>MAP</td><td>Monthly alphalist of payees, attached to 1600-VT and 1600-PT.</td></tr>
<tr><td>QAP</td><td>Quarterly alphalist of payees, attached to 1601-EQ and 1601-FQ. Generated one file per month.</td></tr>
<tr><td>RDO</td><td>Revenue District Office where the taxpayer is registered.</td></tr>
<tr><td>RELIEF</td><td>The BIR's reconciliation of listings: the summary lists of sales, purchases and importations.</td></tr>
<tr><td>SAWT</td><td>Summary alphalist of withholding taxes: taxes others withheld from the client, attached to the client's return.</td></tr>
<tr><td>SLS, SLP, SLI</td><td>Summary lists of sales, purchases and importations.</td></tr>
<tr><td>SRS</td><td>Semestral list of regular suppliers.</td></tr>
<tr><td>TIN branch code</td><td>The 4 digits after the TIN that identify a branch. The head office is 0000.</td></tr>
<tr><td>2307, 2306, 2316</td><td>Certificates of creditable tax withheld, of final tax withheld, and of compensation and tax withheld.</td></tr>
<tr><td>Maker-checker</td><td>A rule that an action needs one person to request it and a different person to approve it.</td></tr>
<tr><td>Superseded</td><td>A file replaced by a newer one for the same period.</td></tr>
</tbody></table>
H
),
));

return array(
    'chapters' => $ch,
    // Pages that share a section with another page.
    'aliases' => array(
        'comp_form' => 'compensation', 'employee_form' => 'employees', 'cp_form' => 'directory', 'import_stage' => 'import_review',
        'org_new' => 'orgs', 'help' => 'intro', 'manual' => 'intro', 'search' => 'navigation', 'notifications' => 'navigation',
        'switch_client' => 'navigation', 'settings' => 'signing_in',
    ),
);

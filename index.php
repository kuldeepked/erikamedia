<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$today         = date('l, F j, Y');
$default_date  = date('Y-m-d');
$default_month = date('Y-m');

// Load saved employees for initial page render
$empFile   = __DIR__ . '/employees.json';
$employees = file_exists($empFile) ? (json_decode(file_get_contents($empFile), true) ?: []) : [];

// Suggest the next invoice number (EM-YYYY-NNN) from invoices.json.
$invFile = __DIR__ . '/invoices.json';
$invList = file_exists($invFile) ? (json_decode(file_get_contents($invFile), true) ?: []) : [];
$invYear = date('Y');
$invSeq  = 0;
foreach ($invList as $iv) {
    if (preg_match('/^EM-' . $invYear . '-(\d+)$/', (string) ($iv['invoice_no'] ?? ''), $m)) {
        $invSeq = max($invSeq, (int) $m[1]);
    }
}
$nextInvoiceNo = 'EM-' . $invYear . '-' . str_pad((string) ($invSeq + 1), 3, '0', STR_PAD_LEFT);
$invDueDefault = date('Y-m-d', strtotime('+7 days'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
    <title>Erika Media — HR Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<!-- ═══════════════════════════════════════════
     SIDEBAR
═══════════════════════════════════════════ -->
<aside class="sidebar">
    <div class="logo-area">
        <img src="assets/logo.png" alt="Erika Media" class="sidebar-logo">
        <div class="company-name">Erika Media</div>
        <div class="company-tagline">HR Dashboard</div>
    </div>

    <nav>
        <div class="nav-label">Main</div>

        <a class="nav-item active" id="nav-dashboard" href="#"
           onclick="showTab('dashboard', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                <polyline points="9 22 9 12 15 12 15 22"/>
            </svg>
            Dashboard
        </a>

        <div class="nav-label" style="margin-top: 12px;">Documents</div>

        <a class="nav-item" id="nav-offer" href="#"
           onclick="showTab('offer', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
            </svg>
            Offer Letter
        </a>

        <a class="nav-item" id="nav-payslip" href="#"
           onclick="showTab('payslip', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="2" y="5" width="20" height="14" rx="2"/>
                <line x1="2" y1="10" x2="22" y2="10"/>
            </svg>
            Payslip
        </a>

        <a class="nav-item" href="letterhead.php" target="_blank">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <line x1="3" y1="8" x2="21" y2="8"/>
                <line x1="3" y1="19" x2="21" y2="19"/>
            </svg>
            Blank Letterhead
        </a>

        <a class="nav-item" id="nav-invoice" href="#"
           onclick="showTab('invoice', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="9" y1="13" x2="15" y2="13"/>
                <line x1="9" y1="17" x2="13" y2="17"/>
            </svg>
            Invoices
        </a>

        <a class="nav-item" id="nav-history" href="#"
           onclick="showTab('history', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="10"/>
                <polyline points="12 6 12 12 16 14"/>
            </svg>
            History
        </a>

        <div class="nav-label" style="margin-top: 12px;">Operations</div>

        <a class="nav-item" id="nav-activity" href="#"
           onclick="showTab('activity', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
            </svg>
            Activity Log
        </a>

        <a class="nav-item" id="nav-team" href="#"
           onclick="showTab('team', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="9" cy="7" r="4"/>
                <path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                <path d="M21 21v-2a4 4 0 0 0-3-3.87"/>
            </svg>
            Manage Team
        </a>

        <a class="nav-item" href="attendance-admin.php">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="9"/>
                <path d="M12 7v5l3 2"/>
            </svg>
            Attendance
        </a>

        <a class="nav-item" id="nav-payroll" href="#"
           onclick="showTab('payroll', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="2" y="5" width="20" height="14" rx="2"/>
                <line x1="2" y1="10" x2="22" y2="10"/>
                <circle cx="17" cy="15" r="1.5"/>
            </svg>
            Payroll
        </a>

        <a class="nav-item" id="nav-finances" href="#"
           onclick="showTab('finances', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
            Finances
        </a>

        <a class="nav-item" id="nav-accounting" href="#"
           onclick="showTab('accounting', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M4 3h16a1 1 0 0 1 1 1v16a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/>
                <line x1="7" y1="8" x2="17" y2="8"/>
                <line x1="7" y1="12" x2="12" y2="12"/>
                <line x1="7" y1="16" x2="14" y2="16"/>
            </svg>
            Accounting
        </a>

        <a class="nav-item" id="nav-recurring" href="#"
           onclick="showTab('recurring', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <polyline points="23 4 23 10 17 10"/>
                <polyline points="1 20 1 14 7 14"/>
                <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
            </svg>
            Recurring
        </a>

        <a class="nav-item" id="nav-reports" href="#"
           onclick="showTab('reports', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <line x1="18" y1="20" x2="18" y2="10"/>
                <line x1="12" y1="20" x2="12" y2="4"/>
                <line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
            Reports
        </a>

        <a class="nav-item" id="nav-fin-setup" href="#"
           onclick="showTab('fin-setup', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <circle cx="12" cy="12" r="3"/>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
            </svg>
            Finance Setup
        </a>

        <div class="nav-label" style="margin-top: 12px;">Account</div>

        <a class="nav-item" href="change-password.php">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            Change Password
        </a>

        <a class="nav-item" href="setup-2fa.php">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                <path d="M9 12l2 2 4-4"/>
            </svg>
            Two-Factor Auth
        </a>

        <a class="nav-item" href="logout.php">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <polyline points="16 17 21 12 16 7"/>
                <line x1="21" y1="12" x2="9" y2="12"/>
            </svg>
            Sign Out
        </a>
    </nav>

    <div class="sidebar-footer">
        Erika Media &copy; <?= date('Y') ?><br>
        Office No. 505, 5th Floor<br>
        Kashif Center, Sharah-e-Faisal Karachi
    </div>
</aside>

<!-- ═══════════════════════════════════════════
     MAIN
═══════════════════════════════════════════ -->
<div class="main">
    <div class="topbar">
        <h1 id="page-title">Dashboard</h1>
        <div style="display: flex; align-items: center; gap: 16px;">
            <span class="topbar-user">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_user']) ?></strong></span>
            <span class="topbar-date"><?= $today ?></span>
        </div>
    </div>

    <div class="content-area">

        <!-- ─────────────────────────────────────
             DASHBOARD — overview
        ───────────────────────────────────── -->
        <div id="tab-dashboard" class="tab-content active">
            <div id="dashboard-body"><p class="emp-empty">Loading&hellip;</p></div>
        </div>

        <!-- ─────────────────────────────────────
             OFFER LETTER FORM
        ───────────────────────────────────── -->
        <div id="tab-offer" class="tab-content">
            <div class="card">
                <div class="card-title">Offer Letter Generator</div>
                <div class="card-subtitle">
                    Select an employee below. Salary auto-fills from their profile if set.
                    Then click <strong>Generate</strong> and use <strong>Print &rarr; Save as PDF</strong>.
                </div>

                <form action="generate-offer.php" method="POST" target="_blank">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">

                    <div class="section-label">Employee Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <select name="employee_name" id="offer-name"
                                    onchange="syncFromProfile('offer')" required>
                                <option value="">— Select Employee —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Department *</label>
                            <select name="position" id="offer-position" required>
                                <option value="">— Select Department —</option>
                            </select>
                        </div>
                    </div>

                    <div class="section-label">Dates</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Letter Date *</label>
                            <input type="date" name="letter_date" value="<?= $default_date ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Start Date *</label>
                            <input type="date" name="start_date" value="<?= $default_date ?>" required>
                        </div>
                    </div>

                    <div class="section-label">Compensation</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Basic Salary (Rs.) *</label>
                            <input type="number" name="basic_salary" id="offer-basic" min="0" placeholder="e.g. 45000" required>
                        </div>
                        <div class="form-group">
                            <label>Allowance (Rs.)</label>
                            <input type="number" name="travel_allowance" id="offer-allowance" min="0" value="5000">
                        </div>
                    </div>

                    <div class="section-label">Signatory</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Signing Authority Name</label>
                            <input type="text" name="signatory" value="Kuldeep Kumar">
                        </div>
                    </div>

                    <button type="submit" class="btn-generate">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12 a2 2 0 0 0 2-2V8z"/>
                            <polyline points="14 2 14 8 20 8"/>
                        </svg>
                        Generate Offer Letter
                    </button>
                </form>
            </div>
        </div>

        <!-- ─────────────────────────────────────
             PAYSLIP FORM
        ───────────────────────────────────── -->
        <div id="tab-payslip" class="tab-content">
            <div class="card">
                <div class="card-title">Payslip Generator</div>
                <div class="card-subtitle">
                    Pick an employee and pay period, then click <strong>Auto-fill from Activity Log</strong>
                    to pull commissions, penalties and bonuses for that month.
                    Override anything before generating.
                </div>

                <form action="generate-payslip.php" method="POST" target="_blank">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">
                    <input type="hidden" name="paid_activity_ids" id="paid-activity-ids" value="">

                    <div class="section-label">Employee Information</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <select name="employee_name" id="payslip-name"
                                    onchange="syncFromProfile('payslip')" required>
                                <option value="">— Select Employee —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Department *</label>
                            <select name="designation" id="payslip-designation" required>
                                <option value="">— Select Department —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Pay Period *</label>
                            <input type="month" name="pay_period" id="payslip-period" value="<?= $default_month ?>" required
                                   onchange="applyProRatedBasic(); var m=teamMembers.find(function(x){return x.name===document.getElementById('payslip-name').value;}); if(m) showPayslipHints(m, (window.advancesByEmployee||{})[m.name]||0);">
                        </div>
                    </div>

                    <div id="payslip-hints" class="payslip-hints" style="display:none;"></div>

                    <div style="margin: -8px 0 18px;">
                        <button type="button" class="btn-autofill" onclick="autoFillPayslip()">
                            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <polyline points="23 4 23 10 17 10"/>
                                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                            </svg>
                            Auto-fill from Activity Log
                        </button>
                        <span id="autofill-status" class="autofill-status"></span>
                    </div>

                    <div class="section-label">Earnings</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Basic Salary (Rs.) *</label>
                            <input type="number" name="basic_salary" id="ps-basic" min="0" placeholder="e.g. 45000" required>
                        </div>
                        <div class="form-group">
                            <label>Allowance (Rs.)</label>
                            <input type="number" name="allowance" id="ps-allowance" min="0" value="5000">
                        </div>
                        <div class="form-group">
                            <label>Commission (Rs.)</label>
                            <input type="number" name="commission" id="ps-commission" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Punctuality Bonus (Rs.)</label>
                            <input type="number" name="performer_bonus" id="ps-bonus" min="0" value="0">
                        </div>
                    </div>

                    <div class="section-label">Deductions</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Provident Fund (Rs.)</label>
                            <input type="number" name="provident_fund" id="ps-pf" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>EOBI (Rs.)</label>
                            <input type="number" name="eobi" id="ps-eobi" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Loan (Rs.)</label>
                            <input type="number" name="loan" id="ps-loan" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Professional Tax (Rs.)</label>
                            <input type="number" name="professional_tax" id="ps-pt" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Absent / Late Deduction (Rs.)</label>
                            <input type="number" name="absent_late" id="ps-absent" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Penalty (Rs.)</label>
                            <input type="number" name="penalty" id="ps-penalty" min="0" value="0">
                        </div>
                    </div>

                    <button type="submit" class="btn-generate">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="2" y="5" width="20" height="14" rx="2"/>
                            <line x1="2" y1="10" x2="22" y2="10"/>
                        </svg>
                        Generate Payslip
                    </button>
                </form>
            </div>
        </div>

        <!-- ─────────────────────────────────────
             HISTORY
        ───────────────────────────────────── -->
        <div id="tab-history" class="tab-content">
            <div class="card">
                <div class="card-title">Document History</div>
                <div class="card-subtitle">
                    Every generated payslip and offer letter is saved here.
                    Click <strong>Open</strong> to instantly regenerate it in a new tab.
                </div>
                <div id="history-list"><p class="emp-empty">Loading&hellip;</p></div>
            </div>
        </div>

        <!-- ─────────────────────────────────────
             ACTIVITY LOG
        ───────────────────────────────────── -->
        <div id="tab-activity" class="tab-content">

            <!-- Add Activity Entry -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-title">Log Activity</div>
                <div class="card-subtitle">
                    Record interviews, placements, penalties and bonuses as they happen.
                    Each interview automatically credits <strong>Rs. <?= number_format(INTERVIEW_RATE) ?></strong>.
                    Placement amounts are entered manually since they vary.
                </div>

                <div id="activity-alert" class="team-alert"></div>

                <form id="add-activity-form" onsubmit="addActivity(event)">
                    <div class="section-label">Event Details</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Event Type *</label>
                            <select id="act-type" onchange="onActivityTypeChange()" required>
                                <option value="interview">Interview held</option>
                                <option value="placement">Job placement</option>
                                <option value="penalty">Penalty / Mistake</option>
                                <option value="bonus">One-off Bonus</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Employee *</label>
                            <select id="act-employee" required>
                                <option value="">— Select Employee —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" id="act-date" value="<?= $default_date ?>" required>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group" id="act-candidate-group">
                            <label>Candidate Name (optional)</label>
                            <input type="text" id="act-candidate" placeholder="e.g. John Smith" autocomplete="off">
                        </div>
                        <div class="form-group" id="act-client-group" style="display: none;">
                            <label>Client (optional)</label>
                            <input type="text" id="act-client" placeholder="e.g. Acme Corp" autocomplete="off">
                        </div>
                        <div class="form-group" id="act-reason-group" style="display: none;">
                            <label>Reason</label>
                            <input type="text" id="act-reason" placeholder="e.g. Missed deadline" autocomplete="off">
                        </div>
                        <div class="form-group" id="act-amount-group" style="display: none;">
                            <label>Amount (Rs.) *</label>
                            <input type="number" id="act-amount" min="1" placeholder="e.g. 30000">
                        </div>
                    </div>
                    <button type="submit" class="btn-generate">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Log Event
                    </button>
                </form>
            </div>

            <!-- Activity List with filters -->
            <div class="card">
                <div class="card-title">All Activity</div>
                <div class="card-subtitle">
                    Filter by employee or month. Entries marked <strong>Paid</strong> have already
                    been included in a generated payslip and won't be auto-counted again.
                </div>

                <div class="filter-bar">
                    <div class="form-group" style="flex: 1; min-width: 180px;">
                        <label>Filter by Employee</label>
                        <select id="filter-employee" onchange="loadActivityList()">
                            <option value="">All employees</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 160px;">
                        <label>Filter by Month</label>
                        <input type="month" id="filter-month" onchange="loadActivityList()">
                    </div>
                    <div class="form-group" style="flex: 1; min-width: 140px;">
                        <label>Status</label>
                        <select id="filter-status" onchange="loadActivityList()">
                            <option value="">All</option>
                            <option value="unpaid">Unpaid only</option>
                            <option value="paid">Paid only</option>
                        </select>
                    </div>
                </div>

                <div id="activity-list"><p class="emp-empty">Loading&hellip;</p></div>
            </div>

        </div>

        <!-- ─────────────────────────────────────
             MANAGE TEAM
        ───────────────────────────────────── -->
        <div id="tab-team" class="tab-content">

            <!-- Add / Edit Employee -->
            <div class="card" id="emp-form-card" style="margin-bottom: 24px;">
                <div class="card-title" id="emp-form-title">Add Team Member</div>
                <div class="card-subtitle">
                    Saved employees appear instantly in dropdowns across the dashboard.
                    Salary fields auto-fill on payslip and offer letter generation.
                </div>

                <div id="team-alert" class="team-alert"></div>

                <form id="emp-form" onsubmit="saveEmployee(event)">
                    <input type="hidden" id="emp-original-name" value="">

                    <div class="section-label">Basic Information</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" id="emp-name"
                                   placeholder="e.g. Ayesha Khan" autocomplete="off" required>
                        </div>
                        <div class="form-group">
                            <label>Department *</label>
                            <select id="emp-designation" required>
                                <option value="">— Select Department —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Joining Date</label>
                            <input type="date" id="emp-joining-date"
                                   title="Used to pro-rate first-month salary on the payslip">
                        </div>
                    </div>

                    <div class="section-label">Compensation (Monthly Defaults)</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Basic Salary (Rs.) *</label>
                            <input type="number" id="emp-basic" min="0" placeholder="e.g. 45000" required>
                        </div>
                        <div class="form-group">
                            <label>Allowance (Rs.)</label>
                            <input type="number" id="emp-allowance" min="0" value="5000">
                        </div>
                        <div class="form-group">
                            <label>Punctuality Bonus (Rs.)</label>
                            <input type="number" id="emp-punctuality" min="0" value="5000"
                                   title="Auto-fills on payslip; clear to 0 if not earned that month">
                        </div>
                    </div>

                    <div class="section-label">Standard Deductions (Monthly Defaults)</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Provident Fund (Rs.)</label>
                            <input type="number" id="emp-pf" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>EOBI (Rs.)</label>
                            <input type="number" id="emp-eobi" min="0" value="370">
                        </div>
                        <div class="form-group">
                            <label>Professional Tax (Rs.)</label>
                            <input type="number" id="emp-pt" min="0" value="0">
                        </div>
                    </div>

                    <button type="submit" class="btn-generate" id="emp-submit-btn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add to Team
                    </button>
                    <button type="button" class="btn-cancel" id="emp-cancel-btn"
                            style="display: none;" onclick="cancelEmpEdit()">Cancel</button>
                </form>
            </div>

            <!-- Give Advance form (hidden until Give Advance is clicked) -->
            <div id="advance-form-card" class="card" style="display:none; margin-bottom: 24px;">
                <div class="card-title" id="advance-form-title">Give Advance</div>
                <div class="card-subtitle">
                    Records a transfer from your business bank to <strong>Employee Advances</strong>.
                    The amount appears as Outstanding and is auto-deducted on the next payslip's Loan field.
                </div>
                <div id="advance-alert" class="team-alert"></div>
                <form id="advance-form" onsubmit="submitAdvance(event)">
                    <input type="hidden" id="adv-employee-name" value="">
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" id="adv-date" required value="<?= $default_date ?>">
                        </div>
                        <div class="form-group">
                            <label>Amount (Rs.) *</label>
                            <input type="number" id="adv-amount" required min="1" step="1" inputmode="numeric">
                        </div>
                        <div class="form-group">
                            <label>From Bank *</label>
                            <select id="adv-source"></select>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Note (optional)</label>
                            <input type="text" id="adv-note" maxlength="500" placeholder="e.g. requested for medical emergency">
                        </div>
                    </div>
                    <button type="submit" class="btn-generate">Save Advance</button>
                    <button type="button" class="btn-cancel" onclick="closeAdvanceForm()">Cancel</button>
                </form>
            </div>

            <!-- Employee List -->
            <div class="card">
                <div class="card-title">Team Members</div>
                <div class="card-subtitle">
                    Click <strong>Edit</strong> to update salary or joining date.
                    Click <strong>Give Advance</strong> to record an advance you've given to someone &mdash; it'll auto-deduct from their next payslip.
                </div>
                <div id="employee-list"></div>
            </div>

        </div>

        <!-- ─────────────────────────────────────
             FINANCES (unified — books, accounts, categories, splits)
        ───────────────────────────────────── -->
        <div id="tab-finances" class="tab-content">

            <!-- Top: book selector + summary -->
            <div class="finance-topbar">
                <div class="book-selector" id="book-selector">
                    <!-- populated from books-api.php -->
                </div>
                <div class="finance-actions">
                    <button type="button" class="btn-finance-primary"  onclick="openTxForm('income')">+ Money In</button>
                    <button type="button" class="btn-finance-secondary" onclick="openTxForm('expense')">+ Money Out</button>
                    <button type="button" class="btn-finance-secondary" onclick="openTransferForm()">⇄ Transfer</button>
                    <button type="button" class="btn-finance-accent"   onclick="openSplitForm()">✦ Split / Pay Across Books</button>
                </div>
            </div>

            <div id="finance-alert" class="team-alert"></div>

            <!-- Add / edit transaction form (collapsible) -->
            <div id="tx-form-card" class="card" style="display:none;">
                <div class="card-title" id="tx-form-title">Add Transaction</div>
                <form id="tx-form" onsubmit="submitTxForm(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" id="tx-date" required value="<?= $default_date ?>">
                        </div>
                        <div class="form-group">
                            <label>Type *</label>
                            <select id="tx-type" required onchange="onTxTypeChange()">
                                <option value="income">Money In (Income)</option>
                                <option value="expense">Money Out (Expense)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Account *</label>
                            <select id="tx-account" required></select>
                        </div>
                        <div class="form-group">
                            <label>Amount *</label>
                            <input type="number" id="tx-amount" required min="0.01" step="0.01" inputmode="decimal" placeholder="0">
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select id="tx-category"></select>
                        </div>
                        <div class="form-group">
                            <label>Counterparty <span class="muted">(client / vendor / employee)</span></label>
                            <input type="text" id="tx-counterparty" maxlength="200" autocomplete="off">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Description / Notes</label>
                            <input type="text" id="tx-description" maxlength="500" autocomplete="off">
                        </div>
                    </div>
                    <button type="submit" class="btn-generate" id="tx-submit-btn">Save</button>
                    <button type="button" class="btn-cancel" onclick="closeTxForm()">Cancel</button>
                </form>
            </div>

            <!-- Transfer form -->
            <div id="transfer-form-card" class="card" style="display:none;">
                <div class="card-title">Transfer Between My Accounts (within current book)</div>
                <div class="card-subtitle">Move money between two of your own accounts. Doesn't count as income or expense.</div>
                <form id="transfer-form" onsubmit="submitTransferForm(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" id="trn-date" required value="<?= $default_date ?>">
                        </div>
                        <div class="form-group">
                            <label>Amount *</label>
                            <input type="number" id="trn-amount" required min="0.01" step="0.01" inputmode="decimal">
                        </div>
                        <div class="form-group">
                            <label>From Account *</label>
                            <select id="trn-src" required></select>
                        </div>
                        <div class="form-group">
                            <label>To Account *</label>
                            <select id="trn-dst" required></select>
                        </div>
                        <div class="form-group">
                            <label>Counterparty / Person <span class="muted">(optional)</span></label>
                            <input type="text" id="trn-counterparty" maxlength="200" placeholder="e.g. Ahmed (for loans)">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" id="trn-description" maxlength="500">
                        </div>
                    </div>
                    <p class="form-hint">
                        Tip for loans: pick <strong>Loans Receivable</strong> as the destination and put the friend's name in Counterparty. Loan repayments are the reverse.
                    </p>
                    <button type="submit" class="btn-generate">Save Transfer</button>
                    <button type="button" class="btn-cancel" onclick="closeTransferForm()">Cancel</button>
                </form>
            </div>

            <!-- Split form: salary received with auto-split, or pay-Kuldeep cross-book -->
            <div id="split-form-card" class="card" style="display:none;">
                <div class="card-title" id="split-form-title">Split / Pay Across Books</div>
                <div class="card-subtitle">
                    Use this for salary received with the 70/10/10/10 split, or for the business paying you (or anyone)
                    where the money lands in different buckets. Percentages are editable per occurrence.
                </div>
                <form id="split-form" onsubmit="submitSplitForm(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Date *</label>
                            <input type="date" id="spl-date" required value="<?= $default_date ?>">
                        </div>
                        <div class="form-group">
                            <label>Total Amount *</label>
                            <input type="number" id="spl-total" required min="0.01" step="0.01"
                                   inputmode="decimal" oninput="recalcSplitAmounts()">
                        </div>
                        <div class="form-group">
                            <label>Currency *</label>
                            <select id="spl-currency" onchange="renderSplitDestOptions()">
                                <option value="PKR">PKR</option>
                                <option value="USDT">USDT</option>
                                <option value="USD">USD</option>
                                <option value="EUR">EUR</option>
                                <option value="AED">AED</option>
                                <option value="GBP">GBP</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Counterparty (e.g. Erika Media)</label>
                            <input type="text" id="spl-counterparty" maxlength="200">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Description</label>
                            <input type="text" id="spl-description" maxlength="500" placeholder="e.g. May 2026 salary">
                        </div>
                    </div>

                    <div class="split-rows-header">
                        <span>Splits land in <strong id="spl-target-book-label">…</strong> book</span>
                        <button type="button" class="btn-link" onclick="addSplitRow()">+ Add another split</button>
                        <button type="button" class="btn-link" onclick="resetSplitToDefault()">Reset to 70/10/10/10</button>
                    </div>
                    <div id="spl-rows"></div>

                    <label class="split-paired-toggle">
                        <input type="checkbox" id="spl-pair" onchange="onPairToggle()">
                        Also record the matching <strong id="spl-pair-label">expense on the other book</strong>
                        (e.g. business paying salary)
                    </label>
                    <div id="spl-pair-fields" style="display:none;">
                        <div class="form-grid">
                            <div class="form-group">
                                <label>Source Book</label>
                                <select id="spl-pair-book"></select>
                            </div>
                            <div class="form-group">
                                <label>Source Account</label>
                                <select id="spl-pair-account"></select>
                            </div>
                            <div class="form-group">
                                <label>Source Category</label>
                                <select id="spl-pair-category"></select>
                            </div>
                        </div>
                    </div>

                    <div class="split-summary">
                        Total of splits: <strong id="spl-sum-display">0</strong>
                        <span id="spl-sum-warn" class="split-warn"></span>
                    </div>

                    <button type="submit" class="btn-generate">Save Split</button>
                    <button type="button" class="btn-cancel" onclick="closeSplitForm()">Cancel</button>
                </form>
            </div>

            <!-- Summary cards -->
            <div class="finance-summary">
                <div class="fin-summary-card fin-in">
                    <div class="fin-label">Income (period)</div>
                    <div class="fin-value" id="fin-total-in">—</div>
                </div>
                <div class="fin-summary-card fin-out">
                    <div class="fin-label">Expenses (period)</div>
                    <div class="fin-value" id="fin-total-out">—</div>
                </div>
                <div class="fin-summary-card fin-net" id="fin-net-card">
                    <div class="fin-label">Net (period)</div>
                    <div class="fin-value" id="fin-total-net">—</div>
                </div>
            </div>

            <!-- Sub-tabs -->
            <div class="fin-subtabs">
                <button type="button" class="fin-subtab active" data-sub="entries"   onclick="showFinSub('entries')">Entries</button>
                <button type="button" class="fin-subtab"        data-sub="categories" onclick="showFinSub('categories')">By Category</button>
                <button type="button" class="fin-subtab"        data-sub="accounts"  onclick="showFinSub('accounts')">Accounts</button>
                <button type="button" class="fin-subtab"        data-sub="salaries"  onclick="showFinSub('salaries')">Salaries</button>
                <button type="button" class="fin-subtab"        data-sub="loans"     onclick="showFinSub('loans')">Loans</button>
                <button type="button" class="fin-subtab"        data-sub="splits"    onclick="showFinSub('splits')">Splits</button>
            </div>

            <!-- Entries sub-panel -->
            <div id="fin-sub-entries" class="fin-subpanel active">
                <div class="card">
                    <div class="finance-controls">
                        <input type="month" id="fin-filter-month" onchange="loadFinancesEntries()">
                        <select id="fin-filter-account" onchange="loadFinancesEntries()">
                            <option value="">All accounts</option>
                        </select>
                        <select id="fin-filter-category" onchange="loadFinancesEntries()">
                            <option value="">All categories</option>
                        </select>
                        <button type="button" class="finance-clear-filter" onclick="clearFinanceFilter()">Clear</button>
                        <a id="fin-export-link" href="finances-api.php?export=csv" class="finance-export">Export CSV</a>
                    </div>
                    <div id="finances-list"><p class="emp-empty">Loading&hellip;</p></div>
                </div>
            </div>

            <!-- By Category sub-panel -->
            <div id="fin-sub-categories" class="fin-subpanel">
                <div class="card">
                    <div class="card-title">Running totals by category</div>
                    <div class="card-subtitle">All-time totals (excludes voided entries). Click a category to see its history.</div>
                    <div id="cat-totals-list"><p class="emp-empty">Loading&hellip;</p></div>
                </div>
            </div>

            <!-- Accounts sub-panel -->
            <div id="fin-sub-accounts" class="fin-subpanel">
                <div class="card">
                    <div class="card-title">Account balances</div>
                    <div class="card-subtitle">Opening balance + all activity. Click an account to see its transactions.</div>
                    <div id="account-balances-list"><p class="emp-empty">Loading&hellip;</p></div>
                </div>
            </div>

            <!-- Salaries sub-panel -->
            <div id="fin-sub-salaries" class="fin-subpanel">
                <div class="card">
                    <div class="card-title">Per-employee salary history</div>
                    <div class="card-subtitle">All-time totals paid to each employee (across linked categories).</div>
                    <div id="salaries-list"><p class="emp-empty">Loading&hellip;</p></div>
                </div>
            </div>

            <!-- Loans sub-panel -->
            <div id="fin-sub-loans" class="fin-subpanel">
                <div class="card">
                    <div class="card-title">Loans &amp; receivables</div>
                    <div class="card-subtitle">
                        Money you've lent to friends or family, grouped per person.
                        Lend by transferring from your bank to <strong>Loans Receivable</strong> with the borrower's name in <strong>Counterparty</strong>.
                        Repayments are the reverse transfer. Use <strong>Bad Debt Write-off</strong> as the category when you accept the money is not coming back.
                    </div>
                    <div id="loans-list"><p class="emp-empty">Loading&hellip;</p></div>
                </div>
            </div>

            <!-- Splits sub-panel -->
            <div id="fin-sub-splits" class="fin-subpanel">
                <div class="card">
                    <div class="card-title">Recent split events</div>
                    <div class="card-subtitle">Each row is one money event split into multiple destinations (e.g. salary into 70/10/10/10).</div>
                    <div id="splits-list"><p class="emp-empty">Loading&hellip;</p></div>
                </div>
            </div>

        </div>

        <!-- ─────────────────────────────────────
             FINANCE SETUP — books / accounts / categories CRUD
        ───────────────────────────────────── -->
        <div id="tab-fin-setup" class="tab-content">

            <div class="card">
                <div class="card-title">Company &amp; accounting</div>
                <div class="card-subtitle">
                    These appear on every report and export you hand to your accountant, so they are
                    worth getting right once.
                </div>
                <div id="settings-alert" class="team-alert"></div>
                <form id="settings-form" onsubmit="saveSettings(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Company name</label>
                            <input type="text" id="set-company-name" maxlength="120">
                        </div>
                        <div class="form-group">
                            <label>NTN <span class="muted">(national tax number)</span></label>
                            <input type="text" id="set-company-ntn" maxlength="40" placeholder="e.g. 1234567-8">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Address</label>
                            <input type="text" id="set-company-address" maxlength="300">
                        </div>
                        <div class="form-group">
                            <label>Financial year starts</label>
                            <select id="set-fy-month">
                                <option value="7">July &mdash; Pakistan tax year</option>
                                <option value="1">January &mdash; calendar year</option>
                                <option value="4">April</option>
                                <option value="10">October</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Base currency</label>
                            <select id="set-currency">
                                <option>PKR</option><option>USD</option><option>USDT</option>
                                <option>EUR</option><option>AED</option><option>GBP</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Salaries are paid from</label>
                            <select id="set-payroll-account"></select>
                        </div>
                        <div class="form-group">
                            <label>PF / EOBI / tax collect in</label>
                            <select id="set-statutory-account"></select>
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>
                                <input type="checkbox" id="set-autopost" style="width:auto;margin-right:8px;">
                                Book a payslip into the ledger the moment it is generated
                            </label>
                            <p class="form-hint" style="margin-top:8px;">
                                Leave this on. With it off, salaries only reach the books when you press
                                &ldquo;Post to ledger&rdquo; on the Payroll screen, and a month that gets
                                missed leaves the accounts short by an entire payroll.
                            </p>
                        </div>
                    </div>
                    <button type="submit" class="btn-generate">Save</button>
                </form>
            </div>

            <div class="setup-toolbar">
                <div class="setup-toolbar-text">
                    <strong>New here?</strong> One click creates the typical accounts &amp; categories
                    for a business + personal + charity setup. Anything you already have is skipped &mdash; it&rsquo;s safe to re-run.
                </div>
                <button type="button" class="btn-suggest" onclick="seedSuggestedSetup()">
                    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24">
                        <path d="M5 12l5 5L20 7"/>
                    </svg>
                    Apply suggested setup
                </button>
            </div>

            <div class="card">
                <div class="card-title">Books</div>
                <div class="card-subtitle">Top-level ledgers. Each book is a separate "wallet" of money &mdash; e.g. Erika Media (business), you (personal), or a charity fund. Transfers between books are explicit, so you always know whose money is whose.</div>
                <div id="setup-books-alert" class="team-alert"></div>
                <div id="setup-books-list"><p class="emp-empty">Loading&hellip;</p></div>
                <details class="setup-add">
                    <summary>+ Add new book</summary>
                    <form onsubmit="setupCreateBook(event)">
                        <div class="form-grid">
                            <div class="form-group"><label>Name *</label><input type="text" id="newbook-name" required></div>
                            <div class="form-group"><label>Type</label>
                                <select id="newbook-type">
                                    <option value="business">Business</option>
                                    <option value="personal">Personal</option>
                                </select>
                            </div>
                        </div>
                        <button type="submit" class="btn-generate">Create Book</button>
                    </form>
                </details>
            </div>

            <div class="card">
                <div class="card-title">Accounts</div>
                <div class="card-subtitle">
                    Where money physically sits &mdash; a bank account, cash drawer, Easypaisa wallet, Binance, etc.
                    The same account can hold money for multiple books at once (e.g. UBL holding both business revenue and your personal share of a salary).
                    The optional <strong>Primary book</strong> below is a default hint for new transactions and where opening balance is attributed; it does not restrict which books may use the account.
                </div>
                <div id="setup-accounts-alert" class="team-alert"></div>
                <div id="setup-accounts-list"><p class="emp-empty">Loading&hellip;</p></div>
                <details class="setup-add">
                    <summary>+ Add new account</summary>
                    <form onsubmit="setupCreateAccount(event)">
                        <div class="form-grid">
                            <div class="form-group"><label>Name *</label>
                                <input type="text" id="newacc-name" required placeholder="e.g. HBL Erika Media current">
                            </div>
                            <div class="form-group"><label>Type *</label>
                                <select id="newacc-type">
                                    <option value="bank">Bank</option>
                                    <option value="cash">Cash</option>
                                    <option value="wallet">Wallet (Easypaisa/JazzCash)</option>
                                    <option value="crypto">Crypto / Binance</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Currency *</label>
                                <select id="newacc-currency">
                                    <option value="PKR">PKR — Pakistani Rupee</option>
                                    <option value="USDT">USDT — Tether (Binance)</option>
                                    <option value="USD">USD</option>
                                    <option value="EUR">EUR</option>
                                    <option value="AED">AED</option>
                                    <option value="GBP">GBP</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Book (optional, blank = shared)</label>
                                <select id="newacc-book"><option value="">Shared</option></select>
                            </div>
                            <div class="form-group"><label>Opening balance</label>
                                <input type="number" id="newacc-opening" step="0.01" value="0">
                            </div>
                            <div class="form-group" style="grid-column: span 2;">
                                <label>Notes</label>
                                <input type="text" id="newacc-notes" maxlength="200">
                            </div>
                        </div>
                        <button type="submit" class="btn-generate">Create Account</button>
                    </form>
                </details>
            </div>

            <div class="card">
                <div class="card-title">Categories</div>
                <div class="card-subtitle">
                    What the money was for &mdash; "Electricity", "Salaries", "Charity Giving". Independent of which account paid it.
                    Use parents (e.g. "Salaries") with children per employee. Scope a category to one book if it only applies there.
                </div>
                <div id="setup-categories-alert" class="team-alert"></div>
                <div id="setup-categories-list"><p class="emp-empty">Loading&hellip;</p></div>
                <details class="setup-add">
                    <summary>+ Add new category</summary>
                    <form onsubmit="setupCreateCategory(event)">
                        <div class="form-grid">
                            <div class="form-group"><label>Name *</label>
                                <input type="text" id="newcat-name" required placeholder="e.g. Electricity, Rent, Salaries, Charity">
                            </div>
                            <div class="form-group"><label>Type *</label>
                                <select id="newcat-type">
                                    <option value="expense">Expense</option>
                                    <option value="income">Income</option>
                                </select>
                            </div>
                            <div class="form-group"><label>Parent (optional)</label>
                                <select id="newcat-parent"><option value="">— top-level —</option></select>
                            </div>
                            <div class="form-group"><label>Book scope (optional)</label>
                                <select id="newcat-book"><option value="">Any book</option></select>
                            </div>
                            <div class="form-group" style="grid-column: span 2;">
                                <label>Linked employee (optional, for per-employee salary tracking)</label>
                                <input type="text" id="newcat-employee" placeholder="exact employee name">
                            </div>
                        </div>
                        <button type="submit" class="btn-generate">Create Category</button>
                    </form>
                </details>
                <div class="setup-tools">
                    <button type="button" class="btn-tool" onclick="setupSyncEmployeeCategories()">
                        Sync employee salary sub-categories
                    </button>
                    <span class="muted">Pick a parent expense category, then click — creates one sub-category per employee in employees.json.</span>
                </div>
            </div>
        </div>

        <!-- ─────────────────────────────────────
             CREATE INVOICE
        ───────────────────────────────────── -->
        <div id="tab-invoice" class="tab-content">
            <div class="card">
                <div class="card-title">Create Invoice</div>
                <div class="card-subtitle">Bill a client — fill in the details and line items, then Generate to open a print-ready invoice in a new tab.</div>

                <form action="generate-invoice.php" method="POST" target="_blank" onsubmit="return invoiceBeforeSubmit()">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES) ?>">

                    <div class="section-label">Bill To</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Client name *</label>
                            <input type="text" name="client_name" required placeholder="e.g. Acme Corp">
                        </div>
                        <div class="form-group">
                            <label>Client email</label>
                            <input type="text" name="client_email" placeholder="billing@client.com">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Client address</label>
                            <input type="text" name="client_address" placeholder="Street, City, Country">
                        </div>
                    </div>

                    <div class="section-label">Invoice Details</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Invoice #</label>
                            <input type="text" name="invoice_no" value="<?= htmlspecialchars($nextInvoiceNo, ENT_QUOTES) ?>">
                        </div>
                        <div class="form-group">
                            <label>Issue date</label>
                            <input type="date" name="issue_date" value="<?= $default_date ?>">
                        </div>
                        <div class="form-group">
                            <label>Due date</label>
                            <input type="date" name="due_date" value="<?= $invDueDefault ?>">
                        </div>
                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency" onchange="recalcInvoice()">
                                <option value="Rs.">PKR — Rs.</option>
                                <option value="$">USD — $</option>
                            </select>
                        </div>
                    </div>

                    <div class="section-label">Line Items</div>
                    <div class="invoice-head">
                        <span>Description</span><span>Qty</span><span>Unit price</span><span class="r">Amount</span><span></span>
                    </div>
                    <div id="invoice-items"></div>
                    <button type="button" class="btn-tool" style="margin-top:4px;" onclick="addInvoiceItem()">＋ Add line</button>

                    <div class="form-grid form-grid-3" style="margin-top:20px;">
                        <div class="form-group">
                            <label>Tax (%)</label>
                            <input type="number" name="tax_percent" id="inv-tax" min="0" step="0.01" value="0" oninput="recalcInvoice()">
                        </div>
                    </div>

                    <div class="invoice-summary" id="invoice-summary"></div>

                    <div class="form-group" style="margin-top:18px;">
                        <label>Notes / payment terms</label>
                        <input type="text" name="notes" placeholder="e.g. Payable within 7 days · Bank: HBL — Erika Media, Acct 1234…">
                    </div>

                    <button type="submit" class="btn-generate">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        Generate Invoice
                    </button>
                </form>
            </div>
        </div>

        <!-- ─────────────────────────────────────
             PAYROLL RUN
        ───────────────────────────────────── -->
        <div id="tab-payroll" class="tab-content">

            <div class="range-bar">
                <div class="range-presets" id="payroll-presets"></div>
                <div class="range-custom">
                    <input type="month" id="payroll-from" onchange="payrollCustom()">
                    <span>to</span>
                    <input type="month" id="payroll-to" onchange="payrollCustom()">
                </div>
                <select id="payroll-account" onchange="payrollSaveAccount()" title="Account salaries are paid from">
                    <option value="">— paying account —</option>
                </select>
                <div class="range-spacer"></div>
                <button class="btn-finance-secondary" onclick="loadPayroll()">&#8635; Refresh</button>
            </div>

            <div id="payroll-alert" class="team-alert"></div>

            <div class="card" style="margin-bottom:18px;">
                <div class="section-head" style="margin-bottom:12px;">
                    <div class="card-title" style="font-size:13px;">Who to include</div>
                    <div style="font-size:12px;color:var(--text-muted);" id="payroll-picked"></div>
                </div>
                <div class="chip-select" id="payroll-chips"></div>
            </div>

            <div id="payroll-summary"></div>

            <div class="card" style="margin-bottom:18px;">
                <div class="finance-actions">
                    <button class="btn-finance-primary" id="payroll-genall" onclick="payrollGenerateAll()">
                        Generate &amp; post all pending
                    </button>
                    <button class="btn-finance-accent" onclick="payrollDownloadAll()">
                        &#8681; Download all payslips (one PDF)
                    </button>
                    <button class="btn-finance-secondary" id="payroll-postbtn" onclick="payrollPostUnposted()">
                        Post unposted to ledger
                    </button>
                </div>
                <div id="payroll-action-alert" class="team-alert" style="margin-top:14px;"></div>
                <p class="form-hint" style="margin-top:14px;">
                    Generating a payslip books it straight into the ledger &mdash; net pay against the paying
                    account, any advance recovered against Employee Advances, and PF, EOBI and tax against
                    Statutory Payables. Re-generating a month replaces the earlier slip and reverses what it
                    booked, so nothing is ever counted twice.
                </p>
            </div>

            <div id="payroll-body"><p class="emp-empty">Loading&hellip;</p></div>
        </div>

        <!-- ─────────────────────────────────────
             REPORTS & ANALYTICS
        ───────────────────────────────────── -->
        <div id="tab-reports" class="tab-content">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="card-title">Reports &amp; Analytics</div>
                        <div style="font-size:13px; color:var(--text-muted); margin-top:4px;">Income, expenses, and team performance over time.</div>
                    </div>
                    <div class="finance-controls" style="margin-bottom:0;">
                        <input type="month" id="report-month" value="<?= date('Y-m') ?>" onchange="loadReports()">
                        <a href="#" class="finance-export" onclick="exportReportCsv(); return false;">&#8681; Export CSV</a>
                    </div>
                </div>
                <div id="reports-summary"></div>
            </div>
            <div id="reports-charts"></div>
        </div>

        <!-- ─────────────────────────────────────
             ACCOUNTING
        ───────────────────────────────────── -->
        <div id="tab-accounting" class="tab-content">

            <div class="range-bar">
                <div class="range-presets" id="acct-presets"></div>
                <div class="range-custom">
                    <input type="date" id="acct-from" onchange="acctCustom()">
                    <span>to</span>
                    <input type="date" id="acct-to" onchange="acctCustom()">
                </div>
                <select id="acct-book" onchange="acctReload()">
                    <option value="business">Business only</option>
                    <option value="all">All books</option>
                </select>
                <div class="range-spacer"></div>
                <div class="range-label">Showing <strong id="acct-range-label">&hellip;</strong></div>
            </div>

            <div id="acct-alert" class="team-alert"></div>

            <div class="fin-subtabs">
                <button type="button" class="fin-subtab active" data-sub="overview"  onclick="acctSub('overview')">Overview</button>
                <button type="button" class="fin-subtab"        data-sub="pl"        onclick="acctSub('pl')">Profit &amp; Loss</button>
                <button type="button" class="fin-subtab"        data-sub="trial"     onclick="acctSub('trial')">Trial Balance</button>
                <button type="button" class="fin-subtab"        data-sub="ledger"    onclick="acctSub('ledger')">General Ledger</button>
                <button type="button" class="fin-subtab"        data-sub="statement" onclick="acctSub('statement')">Account Statement</button>
                <button type="button" class="fin-subtab"        data-sub="expenses"  onclick="acctSub('expenses')">Expenses by Month</button>
                <button type="button" class="fin-subtab"        data-sub="payroll"   onclick="acctSub('payroll')">Payroll Register</button>
                <button type="button" class="fin-subtab"        data-sub="health"    onclick="acctSub('health')">Health</button>
                <button type="button" class="fin-subtab"        data-sub="pack"      onclick="acctSub('pack')">Tax Pack</button>
            </div>

            <div id="acct-body"><p class="emp-empty">Loading&hellip;</p></div>
        </div>

        <!-- ─────────────────────────────────────
             RECURRING
        ───────────────────────────────────── -->
        <div id="tab-recurring" class="tab-content">
            <div class="card">
                <div class="section-head">
                    <div>
                        <div class="card-title">Recurring money in &amp; out</div>
                        <div style="font-size:13px; color:var(--text-muted); margin-top:4px;">
                            Rent, internet, subscriptions &mdash; the fixed amounts that otherwise get
                            remembered late. A rule never books the same month twice.
                        </div>
                    </div>
                    <div class="finance-controls" style="margin-bottom:0;">
                        <button class="btn-finance-secondary" onclick="loadRecurring()">&#8635; Refresh</button>
                        <button class="btn-finance-primary" id="rec-run-all" onclick="recRunAll()">Post everything due</button>
                        <button class="btn-finance-accent" onclick="recOpenForm()">+ New rule</button>
                    </div>
                </div>
                <div id="rec-alert" class="team-alert"></div>
                <div id="rec-body"><p class="emp-empty">Loading&hellip;</p></div>
            </div>

            <div class="card" id="rec-form-card" style="display:none;">
                <div class="card-title" id="rec-form-title">New recurring rule</div>
                <form id="rec-form" onsubmit="recSubmit(event)">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Name *</label>
                            <input type="text" id="rec-name" required maxlength="120" placeholder="e.g. Office rent">
                        </div>
                        <div class="form-group">
                            <label>Type *</label>
                            <select id="rec-type" required onchange="recTypeChange()">
                                <option value="expense">Money Out (Expense)</option>
                                <option value="income">Money In (Income)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Amount *</label>
                            <input type="number" id="rec-amount" required min="0.01" step="0.01" inputmode="decimal">
                        </div>
                        <div class="form-group">
                            <label>Account *</label>
                            <select id="rec-account" required></select>
                        </div>
                        <div class="form-group">
                            <label>Category</label>
                            <select id="rec-category"></select>
                        </div>
                        <div class="form-group">
                            <label>Day of month *</label>
                            <input type="number" id="rec-day" required min="1" max="31" value="1">
                        </div>
                        <div class="form-group">
                            <label>Starts *</label>
                            <input type="month" id="rec-start" required>
                        </div>
                        <div class="form-group">
                            <label>Ends <span class="muted">(optional)</span></label>
                            <input type="month" id="rec-end">
                        </div>
                        <div class="form-group">
                            <label>Counterparty</label>
                            <input type="text" id="rec-counterparty" maxlength="200" placeholder="e.g. Landlord">
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <input type="text" id="rec-description" maxlength="500">
                        </div>
                    </div>
                    <p class="form-hint">
                        A day later than the month is short gets pulled back to the last day &mdash;
                        day 31 in February books on the 28th, never on 3 March.
                    </p>
                    <button type="submit" class="btn-generate">Save rule</button>
                    <button type="button" class="btn-cancel" onclick="recCloseForm()">Cancel</button>
                </form>
            </div>
        </div>

    </div><!-- /content-area -->
</div><!-- /main -->

<!-- ═══════════════════════════════════════════
     DIALOG
     Edit forms live at the top of their screen. Opening one used to scroll
     the page up to it, which loses your place in a long list and means
     scrolling back down after every single edit. Instead the form is lifted
     into this overlay and put back where it came from on close, so the page
     underneath never moves.
═══════════════════════════════════════════ -->
<div id="modal-backdrop" class="modal-backdrop" onclick="modalBackdropClick(event)">
    <div class="modal-shell" id="modal-shell">
        <button type="button" class="modal-close" onclick="closeCardModal()" aria-label="Close">&times;</button>
    </div>
</div>

<script>
// ── Globals ───────────────────────────────────────────────────────────────
var teamMembers = <?= json_encode(array_values($employees)) ?>;
var DEPARTMENTS = ['Team Lead', 'Reverse Recruiting Agent', 'Office Boy', 'Quality Assurance', 'Operations'];
var CSRF        = document.querySelector('meta[name="csrf-token"]').content;
var INTERVIEW_RATE = <?= INTERVIEW_RATE ?>;

// ── Dialog ────────────────────────────────────────────────────────────────
// Every edit form on this dashboard sits at the top of its screen, so opening
// one scrolled the page up and lost your place in the list below — after each
// edit you had to scroll all the way back down to reach the next row.
//
// Rather than rebuild each form as a dialog, the existing card is moved into
// the overlay and moved back on close. Same element, same ids, same submit
// handlers; only where it sits on the page changes, and the page underneath
// never scrolls.

var _modal = { cardId: null, placeholder: null, prevDisplay: '', marked: null };

function openCardModal(cardId, markEl) {
    var card = document.getElementById(cardId);
    if (!card) return;
    closeCardModal();

    // Remember the exact spot so the card goes back where it belongs. The Team
    // form is visible inline for "add", so its previous display has to be
    // restored too rather than assumed to be "none".
    var ph = document.createElement('div');
    ph.style.display = 'none';
    card.parentNode.insertBefore(ph, card);

    _modal = {
        cardId: cardId,
        placeholder: ph,
        prevDisplay: card.style.display,
        marked: markEl || null,
    };

    document.getElementById('modal-shell').appendChild(card);
    card.style.display = 'block';
    if (markEl) markEl.classList.add('row-editing');

    var backdrop = document.getElementById('modal-backdrop');
    backdrop.classList.add('open');
    document.body.style.overflow = 'hidden';

    var first = card.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
    if (first) setTimeout(function () { try { first.focus(); } catch (e) {} }, 60);
}

function closeCardModal() {
    if (!_modal.cardId) return;
    var card = document.getElementById(_modal.cardId);
    var ph   = _modal.placeholder;
    if (card && ph && ph.parentNode) {
        ph.parentNode.insertBefore(card, ph);
        ph.parentNode.removeChild(ph);
        card.style.display = _modal.prevDisplay;
    }
    if (_modal.marked) _modal.marked.classList.remove('row-editing');

    document.getElementById('modal-backdrop').classList.remove('open');
    document.body.style.overflow = '';
    _modal = { cardId: null, placeholder: null, prevDisplay: '', marked: null };
}

function modalIsOpen() { return !!_modal.cardId; }

// Only a click on the backdrop itself closes; clicks inside the form bubble up
// here too and must not dismiss half-finished work.
function modalBackdropClick(ev) {
    if (ev.target === document.getElementById('modal-backdrop')) closeCardModal();
}

document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && modalIsOpen()) closeCardModal();
});

// ── Centralized fetch with CSRF ───────────────────────────────────────────
function apiPost(url, payload) {
    return fetch(url, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body:    JSON.stringify(payload),
    }).then(function (r) { return r.json(); });
}

// ── Dashboard (overview) ──────────────────────────────────────────────────
function loadDashboard() {
    var body = document.getElementById('dashboard-body');
    if (!body) return;
    fetch('dashboard-api.php')
        .then(function (r) { return r.json(); })
        .then(function (d) { renderDashboard(d); })
        .catch(function () {
            var b = document.getElementById('dashboard-body');
            if (b) b.innerHTML = '<p class="emp-empty">Could not load dashboard.</p>';
        });
}

function rsFmt(n) { return 'Rs. ' + numFmt(Math.round(n || 0)); }

function dashDelta(cur, prev) {
    cur = cur || 0; prev = prev || 0;
    if (prev === 0) {
        if (cur === 0) return '<div class="kpi-sub">No prior-month data</div>';
        return '<div class="kpi-delta up">▲ new this month</div>';
    }
    var pct = ((cur - prev) / Math.abs(prev)) * 100;
    var up = pct >= 0;
    return '<div class="kpi-delta ' + (up ? 'up' : 'down') + '">'
         + (up ? '▲' : '▼') + ' ' + Math.abs(pct).toFixed(1) + '% vs last month</div>';
}

function renderDashboard(d) {
    var k = d.kpis || {};
    var cf = d.cashflow || [];

    var html = dashAttention(d.attention || {});

    html += '<div class="kpi-grid">';
    html += dashKpi('Net — this month', rsFmt(k.net), dashDelta(k.net, k.net_prev), 'money');
    html += dashKpi('Income', rsFmt(k.income), dashDelta(k.income, k.income_prev), '');
    html += dashKpi('Expenses', rsFmt(k.expense), dashDelta(k.expense, k.expense_prev), '');
    html += dashKpi('Interviews', numFmt(k.interviews), '<div class="kpi-sub">' + numFmt(k.placements) + ' placements this month</div>', '');
    html += dashKpi('Headcount', numFmt(k.headcount), '<div class="kpi-sub">active employees</div>', 'people');
    html += dashKpi('Outstanding advances', rsFmt(k.advances_outstanding), '<div class="kpi-sub">recoverable from staff</div>', '');
    html += dashKpi('Outstanding loans', rsFmt(k.loans_outstanding), '<div class="kpi-sub">loans receivable</div>', '');
    html += dashKpi('Owed to EOBI / FBR', rsFmt(k.statutory_owed), '<div class="kpi-sub">withheld, not yet remitted</div>', '');
    html += dashKpi('Base payroll', rsFmt(k.payroll_base), '<div class="kpi-sub">' + numFmt(k.headcount) + ' employees</div>', 'pay');
    html += '</div>';

    html += '<div class="dash-cols"><div class="card">';
    html += '<div class="section-head"><div class="card-title">Cash flow — last 6 months</div>'
          + '<div class="legend"><span><i style="background:var(--series-in)"></i>Income</span>'
          + '<span><i style="background:var(--series-out)"></i>Expense</span></div></div>';
    html += dashChart(cf);
    html += '<div class="section-head" style="margin-top:28px;"><div class="card-title">Recent activity</div>'
          + '<button class="btn-link" onclick="document.getElementById(\'nav-activity\').click()">View all →</button></div>';
    html += dashActivity(d.recent_activity || []);
    html += '</div><div>';
    html += dashTopPerformer(d.top_performer);
    html += '<div class="card" style="margin-top:18px;"><div class="card-title" style="margin-bottom:14px;">Quick actions</div>'
          + '<div class="quick-actions">'
          + '<button class="btn-generate" style="margin-top:0;" onclick="document.getElementById(\'nav-offer\').click()">＋ Offer letter</button>'
          + '<button class="btn-autofill" onclick="document.getElementById(\'nav-payslip\').click()">＋ Payslip</button>'
          + '<button class="btn-tool" onclick="document.getElementById(\'nav-activity\').click()">＋ Log activity</button>'
          + '</div></div></div></div>';

    var b = document.getElementById('dashboard-body');
    if (b) b.innerHTML = html;
}

// Things that are quietly wrong and will not fix themselves — an unposted
// payslip is a month of salary missing from the books, and neither the KPI
// tiles nor the chart would show its absence.
function dashAttention(a) {
    var items = [];
    if (a.ledger_errors) {
        items.push(['sev-error', a.ledger_errors + ' ledger problem' + (a.ledger_errors === 1 ? '' : 's')
                  + ' this financial year', 'These stop the books balancing.',
                    'acctOpenHealth()', 'Review']);
    }
    if (a.unposted_payslips) {
        var span = (a.unposted_from && a.unposted_to)
            ? ' (' + monthLabel(a.unposted_from) + ' – ' + monthLabel(a.unposted_to) + ')' : '';
        items.push(['sev-warning', a.unposted_payslips + ' payslip' + (a.unposted_payslips === 1 ? '' : 's')
                  + ' not in the ledger' + span,
                    'Salary was paid but never booked as an expense. Opens the Payroll screen '
                  + 'over these months — you choose which account paid them before anything is booked.',
                    'payrollOpenUnposted(' + JSON.stringify(a.unposted_from || '')
                        + ',' + JSON.stringify(a.unposted_to || '') + ')',
                    'Review & post']);
    }
    if (a.recurring_due) {
        items.push(['sev-info', a.recurring_due + ' recurring entr' + (a.recurring_due === 1 ? 'y' : 'ies') + ' due',
                    'Rent, subscriptions and the like are waiting to be booked.',
                    "document.getElementById('nav-recurring').click()", 'Review']);
    }
    if (!items.length) return '';

    return '<div style="margin-bottom:18px;">' + items.map(function (i) {
        return '<div class="health-item ' + i[0] + '"><div class="health-head">'
             + '<span class="health-title">' + esc(i[1]) + '</span>'
             + '<div style="flex:1"></div>'
             + '<button class="btn-edit" onclick="' + i[3].replace(/"/g, '&quot;') + '">'
             + esc(i[4]) + ' →</button></div>'
             + '<div class="health-detail">' + esc(i[2]) + '</div></div>';
    }).join('') + '</div>';
}

function acctOpenHealth() {
    document.getElementById('nav-accounting').click();
    acctSub('health');
}

// Jump to Payroll already covering the months that need posting. Landing on
// the current month would show a handful of the slips the prompt just counted,
// and pressing the button there would post only those.
function payrollOpenUnposted(from, to) {
    document.getElementById('nav-payroll').click();
    if (from && to) {
        payrollState.preset = 'custom';
        payrollState.from = from;
        payrollState.to = to;
        document.getElementById('payroll-from').value = from;
        document.getElementById('payroll-to').value = to;
        document.querySelectorAll('#payroll-presets .range-preset').forEach(function (b) {
            b.classList.remove('active');
        });
        loadPayroll();
    }
}

function dashKpi(label, value, sub, icon) {
    var icons = {
        money:  '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
        people: '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="9" cy="7" r="4"/><path d="M3 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2"/></svg>',
        pay:    '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'
    };
    var ic = (icon && icons[icon]) ? '<span class="kpi-ic">' + icons[icon] + '</span>' : '';
    return '<div class="kpi-card"><div class="kpi-top"><span class="kpi-label">' + esc(label) + '</span>' + ic + '</div>'
         + '<div class="kpi-value">' + value + '</div>' + (sub || '') + '</div>';
}

function dashChart(cf) {
    if (!cf.length) return '<p class="emp-empty">No finance data yet.</p>';
    var max = 1;
    cf.forEach(function (m) { max = Math.max(max, m.income || 0, m.expense || 0); });
    var plot = '<div class="chart-plot">';
    var xaxis = '<div class="chart-x">';
    cf.forEach(function (m) {
        var ih = Math.round(((m.income || 0) / max) * 100);
        var eh = Math.round(((m.expense || 0) / max) * 100);
        plot += '<div class="bar-group">'
              + '<div class="bar income" style="height:' + ih + '%" title="Income: ' + rsFmt(m.income) + '"></div>'
              + '<div class="bar expense" style="height:' + eh + '%" title="Expense: ' + rsFmt(m.expense) + '"></div></div>';
        xaxis += '<span>' + esc(m.label || '') + '</span>';
    });
    return plot + '</div>' + xaxis + '</div>';
}

function dashActivity(items) {
    if (!items.length) return '<p class="emp-empty">No recent activity.</p>';
    var dotColor = { interview: 'var(--info)', placement: 'var(--success)', bonus: 'var(--warning)', penalty: 'var(--danger)' };
    var label    = { interview: 'interview secured', placement: 'client placement', bonus: 'bonus', penalty: 'penalty' };
    var html = '';
    items.forEach(function (a) {
        var neg = a.type === 'penalty';
        html += '<div class="act-row"><span class="act-dot" style="background:' + (dotColor[a.type] || 'var(--text-faint)') + '"></span>'
              + '<div class="act-main"><span class="act-who">' + esc(a.employee) + '</span> — ' + esc(label[a.type] || a.type)
              + '<div class="act-meta">' + esc(a.date) + '</div></div>'
              + '<span class="act-amt ' + (neg ? 'neg' : 'pos') + '">' + (neg ? '−' : '+') + rsFmt(a.amount) + '</span></div>';
    });
    return html;
}

function dashTopPerformer(t) {
    if (!t || !t.name) {
        return '<div class="card"><div class="card-title" style="margin-bottom:6px;">Top performer</div>'
             + '<p class="emp-empty">No interviews logged this month yet.</p></div>';
    }
    var initials = t.name.split(' ').map(function (w) { return w.charAt(0); }).join('').substring(0, 2).toUpperCase();
    return '<div class="card"><div class="card-title" style="margin-bottom:14px;">Top performer — this month</div>'
         + '<div style="display:flex; align-items:center; gap:14px;">'
         + '<div class="perf-avatar">' + esc(initials) + '</div>'
         + '<div><div style="font-weight:700; font-size:15px;">' + esc(t.name) + '</div>'
         + '<div style="font-size:12px; color:var(--text-faint);">' + esc(t.designation || '') + '</div></div></div>'
         + '<div style="display:flex; gap:10px; margin-top:16px;">'
         + '<div class="perf-stat"><div class="perf-stat-v">' + numFmt(t.interviews) + '</div><div class="perf-stat-l">Interviews</div></div>'
         + '<div class="perf-stat"><div class="perf-stat-v">' + numFmt(t.placements) + '</div><div class="perf-stat-l">Placements</div></div>'
         + '</div></div>';
}

// ── Reports & Analytics ───────────────────────────────────────────────────
var _reportData = null;

function loadReports() {
    var sum    = document.getElementById('reports-summary');
    var charts = document.getElementById('reports-charts');
    if (!sum || !charts) return;
    sum.innerHTML = '<p class="emp-empty">Loading&hellip;</p>';
    charts.innerHTML = '';
    var month = document.getElementById('report-month').value || '';
    fetch('reports-screen-api.php' + (month ? '?month=' + encodeURIComponent(month) : ''))
        .then(function (r) { return r.json(); })
        .then(function (d) { renderReports(d); })
        .catch(function () { sum.innerHTML = '<p class="emp-empty">Could not load reports.</p>'; });
}

function renderReports(d) {
    _reportData = d;
    var s = d.summary || {};
    var neg = (s.net || 0) < 0;
    document.getElementById('reports-summary').innerHTML =
        '<div class="finance-summary" style="margin-top:18px;">'
      + '<div class="fin-summary-card fin-in"><div class="fin-label">Income (12 mo)</div><div class="fin-value">' + rsFmt(s.income) + '</div></div>'
      + '<div class="fin-summary-card fin-out"><div class="fin-label">Expense (12 mo)</div><div class="fin-value">' + rsFmt(s.expense) + '</div></div>'
      + '<div class="fin-summary-card fin-net' + (neg ? ' fin-net-negative' : '') + '"><div class="fin-label">Net (12 mo)</div><div class="fin-value">' + rsFmt(s.net) + '</div></div>'
      + '</div>';

    var html = '<div class="card" style="margin-top:18px;">'
             + '<div class="section-head"><div class="card-title">Net profit — last 12 months</div>'
             + '<div class="legend"><span><i style="background:var(--accent)"></i>Net (income − expense)</span></div></div>'
             + reportNetChart(d.series || []) + '</div>';

    html += '<div class="dash-cols" style="margin-top:18px;">'
          + '<div class="card"><div class="card-title" style="margin-bottom:18px;">Expenses by category — ' + esc(monthLabel(d.month)) + '</div>'
          + reportCategories(d.categories || []) + '</div>'
          + '<div class="card"><div class="card-title" style="margin-bottom:18px;">Performance by employee — ' + esc(monthLabel(d.month)) + '</div>'
          + reportPeople(d.people || []) + '</div></div>';

    document.getElementById('reports-charts').innerHTML = html;
}

function monthLabel(m) {
    if (!m) return '';
    var names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    var parts = String(m).split('-');
    return (names[parseInt(parts[1], 10) - 1] || '') + ' ' + parts[0];
}

function reportNetChart(series) {
    if (!series.length) return '<p class="emp-empty">No finance data yet.</p>';
    var max = 1;
    series.forEach(function (m) { max = Math.max(max, Math.abs(m.net || 0)); });
    var plot = '<div class="chart-plot">';
    var xaxis = '<div class="chart-x">';
    series.forEach(function (m) {
        var h = Math.round((Math.abs(m.net || 0) / max) * 100);
        var neg = (m.net || 0) < 0;
        plot += '<div class="bar-group"><div class="bar ' + (neg ? 'expense' : 'net') + '" style="height:' + h + '%" title="Net: ' + rsFmt(m.net) + '"></div></div>';
        xaxis += '<span>' + esc(m.label || '') + '</span>';
    });
    return plot + '</div>' + xaxis + '</div>';
}

function reportCategories(cats) {
    if (!cats.length) return '<p class="emp-empty">No expenses recorded for this month.</p>';
    var max = 1;
    cats.forEach(function (c) { max = Math.max(max, c.total || 0); });
    var html = '';
    cats.forEach(function (c) {
        var w = Math.round(((c.total || 0) / max) * 100);
        html += '<div class="hbar"><span class="hbar-name">' + esc(c.name) + '</span>'
              + '<div class="hbar-track"><div class="hbar-fill" style="width:' + w + '%; background:var(--danger);"></div></div>'
              + '<span class="hbar-amt">' + rsFmt(c.total) + '</span></div>';
    });
    return html;
}

function reportPeople(people) {
    if (!people.length) return '<p class="emp-empty">No activity logged for this month.</p>';
    var html = '<table class="emp-table"><thead><tr><th>Employee</th>'
             + '<th style="text-align:right">Interviews</th><th style="text-align:right">Placed</th>'
             + '<th style="text-align:right">Earned</th></tr></thead><tbody>';
    people.forEach(function (p) {
        html += '<tr><td>' + esc(p.employee) + '</td>'
              + '<td style="text-align:right">' + numFmt(p.interviews) + '</td>'
              + '<td style="text-align:right">' + numFmt(p.placements) + '</td>'
              + '<td style="text-align:right">' + rsFmt(p.commission) + '</td></tr>';
    });
    return html + '</tbody></table>';
}

function exportReportCsv() {
    if (!_reportData) return;
    var rows = [['Erika Media — Report', _reportData.month], []];
    rows.push(['Monthly series'], ['Month', 'Income', 'Expense', 'Net']);
    (_reportData.series || []).forEach(function (m) { rows.push([m.month, m.income, m.expense, m.net]); });
    rows.push([], ['Expenses by category — ' + _reportData.month], ['Category', 'Amount']);
    (_reportData.categories || []).forEach(function (c) { rows.push([c.name, c.total]); });
    rows.push([], ['Performance by employee — ' + _reportData.month], ['Employee', 'Interviews', 'Placements', 'Earned']);
    (_reportData.people || []).forEach(function (p) { rows.push([p.employee, p.interviews, p.placements, p.commission]); });
    var csv = rows.map(function (r) {
        return r.map(function (c) {
            c = (c === undefined || c === null) ? '' : String(c);
            return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c;
        }).join(',');
    }).join('\n');
    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'erika-report-' + (_reportData.month || 'export') + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ── Payroll Run ───────────────────────────────────────────────────────────
// Payroll works over a span of months rather than one at a time, because the
// questions that actually get asked — "pay everyone for the quarter", "give me
// every payslip for the year" — are range questions. A single month is just a
// range one month long.

var PAYROLL_PRESETS = [
    ['this_month',    'This month'],
    ['last_month',    'Last month'],
    ['last_3_months', 'Last 3 months'],
    ['last_6_months', 'Last 6 months'],
    ['this_fy',       'This FY'],
    ['all_time',      'All time'],
];

var payrollState = {
    preset:    'this_month',
    from:      '',
    to:        '',
    employees: [],      // empty means everyone
    accounts:  [],
    earliest:  '',      // earliest month we know has payslips
    data:      null,
    started:   false,
};

// `where` picks which banner to use. A message about a button the user just
// pressed goes next to that button — the one at the top of the tab is usually
// scrolled off screen behind a long payroll table, which is how a refusal to
// post could look like nothing happening at all.
function payrollAlert(msg, ok, where) {
    var el = document.getElementById(where === 'action' ? 'payroll-action-alert' : 'payroll-alert');
    el.className = 'team-alert ' + (ok ? 'success' : 'error');
    el.innerHTML = msg;
    el.style.display = 'block';
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (ok) setTimeout(function () { el.style.display = 'none'; }, 8000);
}

// Draw the eye to the account picker and leave it focused, so the fix for the
// message is the thing now under the cursor.
function payrollHighlightAccount() {
    var sel = document.getElementById('payroll-account');
    if (!sel) return;
    sel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    sel.style.borderColor = 'var(--danger)';
    sel.style.boxShadow = '0 0 0 3px var(--danger-soft)';
    try { sel.focus(); } catch (e) {}
    setTimeout(function () { sel.style.borderColor = ''; sel.style.boxShadow = ''; }, 4000);
}

function monthShift(month, delta) {
    var y = parseInt(month.slice(0, 4), 10);
    var m = parseInt(month.slice(5, 7), 10) - 1 + delta;
    y += Math.floor(m / 12);
    m = ((m % 12) + 12) % 12;
    return y + '-' + String(m + 1).padStart(2, '0');
}

function thisMonth() { return new Date().toISOString().slice(0, 7); }

// Pakistan's fiscal year runs July to June. There is no point listing months
// that have not happened, so the range is capped at the current month.
function payrollResolveRange(preset) {
    var now = thisMonth();
    switch (preset) {
        case 'this_month':    return [now, now];
        case 'last_month':    return [monthShift(now, -1), monthShift(now, -1)];
        case 'last_3_months': return [monthShift(now, -2), now];
        case 'last_6_months': return [monthShift(now, -5), now];
        case 'this_fy': {
            var y = parseInt(now.slice(0, 4), 10);
            var m = parseInt(now.slice(5, 7), 10);
            var start = (m >= 7 ? y : y - 1) + '-07';
            return [start, now];
        }
        case 'all_time':
            return [payrollState.earliest || monthShift(now, -11), now];
    }
    return [now, now];
}

function loadPayroll() {
    if (!payrollState.started) {
        document.getElementById('payroll-presets').innerHTML = PAYROLL_PRESETS.map(function (p) {
            return '<button type="button" class="range-preset' + (p[0] === payrollState.preset ? ' active' : '')
                 + '" data-preset="' + p[0] + '" onclick="payrollPreset(\'' + p[0] + '\')">'
                 + esc(p[1]) + '</button>';
        }).join('');
        payrollState.started = true;
    }

    if (payrollState.preset !== 'custom') {
        var r = payrollResolveRange(payrollState.preset);
        payrollState.from = r[0];
        payrollState.to   = r[1];
        document.getElementById('payroll-from').value = r[0];
        document.getElementById('payroll-to').value   = r[1];
    }

    var body = document.getElementById('payroll-body');
    body.innerHTML = '<p class="emp-empty">Loading&hellip;</p>';

    fetch('payroll-api.php?from=' + encodeURIComponent(payrollState.from)
                      + '&to='   + encodeURIComponent(payrollState.to))
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) { body.innerHTML = '<p class="emp-empty">' + esc(d.error) + '</p>'; return; }
            payrollState.data = d;
            if (d.accounts && d.accounts.length) payrollFillAccounts(d);
            if (d.earliest_period) payrollState.earliest = d.earliest_period;
            renderPayroll(d);
        })
        .catch(function (e) {
            body.innerHTML = '<p class="emp-empty">Could not load payroll: ' + esc(e.message || e) + '</p>';
        });
}

function payrollPreset(preset) {
    payrollState.preset = preset;
    document.querySelectorAll('#payroll-presets .range-preset').forEach(function (b) {
        b.classList.toggle('active', b.dataset.preset === preset);
    });
    loadPayroll();
}

function payrollCustom() {
    var f = document.getElementById('payroll-from').value;
    var t = document.getElementById('payroll-to').value;
    if (!f || !t) return;
    if (f > t) { var tmp = f; f = t; t = tmp; }
    payrollState.preset = 'custom';
    payrollState.from = f;
    payrollState.to = t;
    document.querySelectorAll('#payroll-presets .range-preset').forEach(function (b) {
        b.classList.remove('active');
    });
    loadPayroll();
}

function payrollFillAccounts(d) {
    payrollState.accounts = d.accounts || [];
    var sel = document.getElementById('payroll-account');
    if (sel.options.length > 1) return;   // already built
    payrollState.accounts.forEach(function (a) {
        sel.add(new Option(a.name + ' (' + a.currency + ')', a.id));
    });
    if (d.default_account_id) sel.value = d.default_account_id;
}

// Remembering the paying account means it is chosen once rather than on every
// run, which is where a salary previously ended up against the wrong bank.
function payrollSaveAccount() {
    var id = document.getElementById('payroll-account').value;
    if (!id) return;
    apiPost('settings-api.php', { action: 'save', payroll_account_id: id }).catch(function () {});
}

function payrollAccountId() {
    return document.getElementById('payroll-account').value || '';
}

function payrollSelectedEmployees() {
    return payrollState.employees.slice();
}

function payrollToggleEmployee(name) {
    var i = payrollState.employees.indexOf(name);
    if (i >= 0) payrollState.employees.splice(i, 1);
    else payrollState.employees.push(name);
    renderPayroll(payrollState.data);
}

function payrollClearEmployees() {
    payrollState.employees = [];
    renderPayroll(payrollState.data);
}

function renderPayroll(d) {
    if (!d) return;
    var months = d.months || [];
    var picked = payrollState.employees;

    // Chips. The API sends a summary object per person, not a bare name.
    var names = (d.employees || []).map(function (e) {
        return typeof e === 'string' ? e : e.employee;
    });
    document.getElementById('payroll-chips').innerHTML =
        '<button type="button" class="chip chip-all' + (picked.length === 0 ? ' active' : '')
      + '" onclick="payrollClearEmployees()">Everyone</button>'
      + names.map(function (n) {
            return '<button type="button" class="chip' + (picked.indexOf(n) >= 0 ? ' active' : '')
                 + '" onclick="payrollToggleEmployee(' + JSON.stringify(n).replace(/"/g, '&quot;') + ')">'
                 + esc(n) + '</button>';
        }).join('');
    document.getElementById('payroll-picked').textContent =
        picked.length ? (picked.length + ' selected') : 'all ' + names.length + ' employees';

    // Roll the visible rows up into headline figures.
    var tot = { basic: 0, allowance: 0, commission: 0, bonus: 0, loan: 0,
                provident_fund: 0, eobi: 0, professional_tax: 0, penalty: 0, net: 0 };
    var pending = 0, generated = 0;
    months.forEach(function (m) {
        payrollVisibleRows(m).forEach(function (r) {
            Object.keys(tot).forEach(function (k) { tot[k] += (r[k] || 0); });
            if (r.generated) generated++; else pending++;
        });
    });
    var statutory = tot.provident_fund + tot.eobi + tot.professional_tax;

    document.getElementById('payroll-summary').innerHTML =
        '<div class="stat-strip">'
      + '<div class="stat-cell"><div class="sc-label">Months</div><div class="sc-value">' + months.length + '</div>'
      + '<div class="sc-sub">' + esc(monthLabel(payrollState.from)) + ' – ' + esc(monthLabel(payrollState.to)) + '</div></div>'
      + '<div class="stat-cell"><div class="sc-label">Gross</div><div class="sc-value">Rs. '
      + numFmt(tot.basic + tot.allowance + tot.commission + tot.bonus) + '</div></div>'
      + '<div class="stat-cell"><div class="sc-label">Withheld</div><div class="sc-value">Rs. ' + numFmt(statutory) + '</div>'
      + '<div class="sc-sub">PF, EOBI, tax</div></div>'
      + '<div class="stat-cell"><div class="sc-label">Advances</div><div class="sc-value">Rs. ' + numFmt(tot.loan) + '</div>'
      + '<div class="sc-sub">recovered</div></div>'
      + '<div class="stat-cell"><div class="sc-label">Net to pay</div><div class="sc-value">Rs. ' + numFmt(tot.net) + '</div></div>'
      + '<div class="stat-cell"><div class="sc-label">Slips</div><div class="sc-value">' + generated + ' / ' + (generated + pending) + '</div>'
      + '<div class="sc-sub">' + (pending ? pending + ' still pending' : 'all generated') + '</div></div>'
      + '</div>';

    var btn = document.getElementById('payroll-genall');
    btn.disabled = pending === 0;
    btn.textContent = pending ? ('Generate & post ' + pending + ' pending') : 'All generated ✓';

    // Say what pressing it will do, and where the number comes from. "Post
    // unposted to ledger" gave no clue whether it applied to the visible month
    // or the whole range, and no clue that it would refuse without an account.
    var unposted = payrollUnpostedCount();
    var post = document.getElementById('payroll-postbtn');
    post.disabled = unposted === 0;
    post.textContent = unposted
        ? 'Post ' + unposted + ' payslip' + (unposted === 1 ? '' : 's') + ' to ledger'
        : 'All posted ✓';

    var accSel = document.getElementById('payroll-account');
    var note = document.getElementById('payroll-action-alert');
    if (unposted && accSel && !accSel.value) {
        note.className = 'team-alert';
        note.style.display = 'block';
        note.innerHTML = 'Choose which account paid these salaries in the bar above — '
                       + 'that is the only thing standing between here and the ledger.';
    } else if (note && note.className === 'team-alert') {
        note.style.display = 'none';
    }

    if (!months.length) {
        document.getElementById('payroll-body').innerHTML =
            '<p class="emp-empty">No months in this range.</p>';
        return;
    }

    // Newest month first — that is the one being worked on.
    var html = months.slice().reverse().map(function (m, idx) {
        return payrollMonthBlock(m, idx === 0);
    }).join('');
    document.getElementById('payroll-body').innerHTML = html;
}

function payrollVisibleRows(m) {
    var picked = payrollState.employees;
    if (!picked.length) return m.rows || [];
    return (m.rows || []).filter(function (r) { return picked.indexOf(r.employee) >= 0; });
}

function payrollMonthBlock(m, open) {
    var rows = payrollVisibleRows(m);
    var pending = rows.filter(function (r) { return !r.generated; }).length;
    var net = rows.reduce(function (a, r) { return a + (r.net || 0); }, 0);

    var h = '<div class="reg-month' + (open ? ' open' : '') + '" id="pm-' + esc(m.period) + '">'
          + '<div class="reg-month-head" onclick="this.parentNode.classList.toggle(\'open\')">'
          + '<svg class="reg-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>'
          + '<span class="reg-month-title">' + esc(m.label) + '</span>'
          + '<span class="reg-month-meta">' + rows.length + ' employees &middot; Rs. ' + numFmt(net) + ' net</span>'
          + '<div class="reg-spacer"></div>'
          + (pending ? '<span class="status-pill status-pending">' + pending + ' pending</span>'
                     : '<span class="status-pill status-posted">all generated</span>')
          + '</div><div class="reg-month-body">';

    if (!rows.length) {
        h += '<p class="emp-empty">Nobody selected for this month.</p></div></div>';
        return h;
    }

    h += '<div class="finance-table-wrap"><table class="finance-table"><thead><tr>'
       + '<th>Employee</th><th class="num">Basic</th><th class="num">Allow.</th><th class="num">Comm.</th>'
       + '<th class="num">Bonus</th><th class="num">Advance</th><th class="num">PF</th><th class="num">EOBI</th>'
       + '<th class="num">Tax</th><th class="num">Penalty</th><th class="num">Net pay</th><th>Status</th><th></th>'
       + '</tr></thead><tbody>';

    rows.forEach(function (r) {
        var ref = m.period + '|' + r.employee;
        h += '<tr>'
           + '<td>' + esc(r.employee) + '<span class="fin-meta">' + esc(r.designation || '') + '</span></td>'
           + '<td class="num">' + numFmt(r.basic) + '</td>'
           + '<td class="num">' + numFmt(r.allowance) + '</td>'
           + '<td class="num">' + numFmt(r.commission) + '</td>'
           + '<td class="num">' + numFmt(r.bonus) + '</td>'
           + '<td class="num">' + (r.loan ? '<span class="neg">&minus;' + numFmt(r.loan) + '</span>'
                + (r.advance_carried_forward ? '<span class="fin-meta">' + numFmt(r.advance_carried_forward) + ' left</span>' : '')
                : '—') + '</td>'
           + '<td class="num">' + (r.provident_fund ? numFmt(r.provident_fund) : '—') + '</td>'
           + '<td class="num">' + (r.eobi ? numFmt(r.eobi) : '—') + '</td>'
           + '<td class="num">' + (r.professional_tax ? numFmt(r.professional_tax) : '—') + '</td>'
           + '<td class="num">' + (r.penalty ? '<span class="neg">&minus;' + numFmt(r.penalty) + '</span>' : '—') + '</td>'
           + '<td class="num"><strong>Rs. ' + numFmt(r.net) + '</strong></td>'
           + '<td>' + (r.posted ? '<span class="status-pill status-posted">posted</span>'
                     : r.generated ? '<span class="status-pill status-pending">not posted</span>'
                                   : '<span class="unpaid-badge">pending</span>') + '</td>'
           + '<td class="num" style="white-space:nowrap;">'
           + '<button class="btn-edit" onclick="payrollOpenSlip(\'' + esc(ref) + '\')">'
           + (r.generated ? 'Re-gen' : 'Payslip') + '</button></td>'
           + '</tr>';
    });

    var t = m.totals || {};
    h += '<tr class="tot-row"><td>Total — ' + rows.length + '</td>'
       + '<td class="num">' + numFmt(t.basic) + '</td><td class="num">' + numFmt(t.allowance) + '</td>'
       + '<td class="num">' + numFmt(t.commission) + '</td><td class="num">' + numFmt(t.bonus) + '</td>'
       + '<td class="num">' + (t.loan ? '&minus;' + numFmt(t.loan) : '—') + '</td>'
       + '<td class="num">' + numFmt(t.provident_fund) + '</td><td class="num">' + numFmt(t.eobi) + '</td>'
       + '<td class="num">' + numFmt(t.professional_tax) + '</td>'
       + '<td class="num">' + (t.penalty ? '&minus;' + numFmt(t.penalty) : '—') + '</td>'
       + '<td class="num">Rs. ' + numFmt(t.net) + '</td><td colspan="2"></td></tr>';

    h += '</tbody></table></div></div></div>';
    return h;
}

// Open one payslip as a printable page. Unlike the old Re-gen path this does
// NOT set is_regen: under the new engine a repeat generation voids the previous
// slip and reverses everything it booked before writing the replacement, so
// re-generating is the correct way to fix a wrong month. is_regen is now only
// for re-printing an existing document from Document History.
function payrollOpenSlip(ref) {
    var parts = ref.split('|');
    var period = parts[0], name = parts.slice(1).join('|');
    var month = (payrollState.data.months || []).filter(function (m) { return m.period === period; })[0];
    if (!month) return;
    var r = (month.rows || []).filter(function (x) { return x.employee === name; })[0];
    if (!r) return;

    if (r.generated && !confirm('A payslip for ' + name + ' already exists for ' + month.label
        + '.\n\nGenerating again replaces it and reverses what the old one booked, so nothing is '
        + 'counted twice. Continue?')) return;

    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'generate-payslip.php';
    form.target = '_blank';
    form.style.display = 'none';
    var fields = {
        _csrf: CSRF,
        employee_name: r.employee,
        designation: r.designation || '',
        pay_period: period,
        basic_salary: r.basic,
        allowance: r.allowance,
        commission: r.commission,
        performer_bonus: r.bonus,
        provident_fund: r.provident_fund,
        eobi: r.eobi,
        loan: r.loan,
        professional_tax: r.professional_tax,
        absent_late: r.absent_late,
        penalty: r.penalty,
        paid_activity_ids: (r.activity_ids || []).join(','),
        account_id: payrollAccountId(),
    };
    Object.keys(fields).forEach(function (k) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
    setTimeout(function () { try { form.remove(); } catch (e) {} }, 1500);
    setTimeout(loadPayroll, 1800);
}

function payrollGenerateAll() {
    var acc = payrollAccountId();
    if (!acc && !confirm('No paying account is selected, so the first bank or cash account will be '
        + 'used.\n\nContinue anyway?')) return;

    var emps = payrollSelectedEmployees();
    var who = emps.length ? emps.length + ' selected employee(s)' : 'every employee';
    if (!confirm('Generate and post payslips for ' + who + ' from ' + monthLabel(payrollState.from)
        + ' to ' + monthLabel(payrollState.to) + '?\n\nEach one is booked straight into the ledger. '
        + 'Months that already have a payslip are left alone.')) return;

    var btn = document.getElementById('payroll-genall');
    btn.disabled = true;
    btn.textContent = 'Working…';

    apiPost('payslips-api.php', {
        action: 'generate',
        from_period: payrollState.from,
        to_period:   payrollState.to,
        employees:   emps,
        account_id:  acc,
        skip_existing: true,
    }).then(function (d) {
        if (d.error) { payrollAlert(esc(d.error), false); loadPayroll(); return; }
        var made = (d.generated || []).length;
        var msg = '<strong>Generated and posted ' + made + ' payslip'
                + (made === 1 ? '' : 's') + '.</strong>';
        if ((d.skipped || []).length) msg += ' ' + d.skipped.length + ' already existed.';
        if ((d.failed || []).length) {
            msg += '<br>Could not do ' + d.failed.length + ': '
                 + d.failed.map(function (f) {
                       return esc(f.employee + ' ' + f.period + ' — ' + f.error);
                   }).join('; ');
            payrollAlert(msg, false);
        } else {
            payrollAlert(msg, true);
        }
        loadPayroll();
    }).catch(function (e) {
        payrollAlert('Could not generate: ' + esc(e.message || e), false);
        loadPayroll();
    });
}

// One document containing every slip in the range, so a single print produces
// a single PDF rather than one file per employee per month.
function payrollDownloadAll() {
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'payslip-bulk.php';
    form.target = '_blank';
    form.style.display = 'none';

    var fields = {
        _csrf: CSRF,
        from_period: payrollState.from,
        to_period:   payrollState.to,
        account_id:  payrollAccountId(),
    };
    Object.keys(fields).forEach(function (k) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = k; inp.value = fields[k];
        form.appendChild(inp);
    });
    payrollSelectedEmployees().forEach(function (n) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'employees[]'; inp.value = n;
        form.appendChild(inp);
    });

    document.body.appendChild(form);
    form.submit();
    setTimeout(function () { try { form.remove(); } catch (e) {} }, 1500);
}

function payrollUnpostedCount() {
    var n = 0;
    ((payrollState.data || {}).months || []).forEach(function (m) {
        payrollVisibleRows(m).forEach(function (r) { if (r.generated && !r.posted) n++; });
    });
    return n;
}

function payrollPostUnposted() {
    var pending = payrollUnpostedCount();
    if (!pending) {
        payrollAlert('Every payslip in ' + esc(monthLabel(payrollState.from)) + ' – '
                   + esc(monthLabel(payrollState.to)) + ' is already in the ledger. '
                   + 'Widen the date range at the top if you were expecting more.', true, 'action');
        return;
    }

    // Deliberately no fallback. There are bank, cash, personal and charity
    // accounts here; guessing one would book company payroll against whichever
    // happened to sort first, and that is far worse than asking.
    var acc = payrollAccountId();
    if (!acc) {
        payrollAlert('<strong>Choose which account paid these salaries first.</strong><br>'
                   + 'The picker is in the bar at the top of this screen — nothing is booked until '
                   + 'it is set, so the money comes out of the right place.', false, 'action');
        payrollHighlightAccount();
        return;
    }

    var accName = document.getElementById('payroll-account').selectedOptions[0].textContent;
    if (!confirm('Post ' + pending + ' payslip' + (pending === 1 ? '' : 's') + ' to the ledger?\n\n'
        + 'Period: ' + monthLabel(payrollState.from) + ' – ' + monthLabel(payrollState.to) + '\n'
        + 'Paid from: ' + accName + '\n\n'
        + 'This books the salary as an expense — net pay against that account, any advance '
        + 'recovered against Employee Advances, and PF/EOBI/tax against Statutory Payables.')) return;

    var btn = document.getElementById('payroll-postbtn');
    btn.disabled = true;
    btn.textContent = 'Posting…';

    apiPost('payslips-api.php', {
        action: 'post_range',
        from_period: payrollState.from,
        to_period:   payrollState.to,
        account_id:  acc,
    }).then(function (d) {
        if (d.error) { payrollAlert(esc(d.error), false, 'action'); loadPayroll(); return; }
        var n = (d.posted || []).length;
        var msg = n
            ? '<strong>Posted ' + n + ' payslip' + (n === 1 ? '' : 's') + ' to the ledger'
              + (d.amount_total ? ' — Rs. ' + numFmt(d.amount_total) : '') + '.</strong>'
            : 'Nothing needed posting in this range.';
        if ((d.failed || []).length) {
            msg += '<br>' + d.failed.length + ' could not be posted: '
                 + d.failed.slice(0, 5).map(function (f) {
                       return esc(f.employee + ' ' + f.period + ' — ' + f.error);
                   }).join('; ');
            payrollAlert(msg, false, 'action');
        } else {
            payrollAlert(msg, true, 'action');
        }
        loadPayroll();
    }).catch(function (e) {
        payrollAlert('Could not post: ' + esc(e.message || e), false, 'action');
        loadPayroll();
    });
}

// ── Invoices ──────────────────────────────────────────────────────────────
function addInvoiceItem(qty, price) {
    var wrap = document.getElementById('invoice-items');
    if (!wrap) return;
    var row = document.createElement('div');
    row.className = 'invoice-row';
    row.innerHTML =
        '<input type="text" name="item_desc[]" placeholder="Service / item description" oninput="recalcInvoice()">'
      + '<input type="number" name="item_qty[]" min="0" step="0.01" value="' + (qty || 1) + '" oninput="recalcInvoice()">'
      + '<input type="number" name="item_price[]" min="0" step="0.01" value="' + (price != null ? price : '') + '" placeholder="0" oninput="recalcInvoice()">'
      + '<span class="invoice-amt">—</span>'
      + '<button type="button" class="invoice-del" title="Remove" onclick="this.parentNode.remove(); recalcInvoice();">&times;</button>';
    wrap.appendChild(row);
    recalcInvoice();
}

function invoiceCurrency() {
    var el = document.querySelector('#tab-invoice [name="currency"]');
    return (el && el.value.trim()) || 'Rs.';
}

function recalcInvoice() {
    var cur = invoiceCurrency();
    var dec = /rs|pkr/i.test(cur) ? 0 : 2;
    var fmt = function (v) { return cur + ' ' + v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }); };

    var subtotal = 0;
    document.querySelectorAll('#invoice-items .invoice-row').forEach(function (r) {
        var q = parseFloat(r.querySelector('[name="item_qty[]"]').value || 0);
        var p = parseFloat(r.querySelector('[name="item_price[]"]').value || 0);
        var amt = (isNaN(q) ? 0 : q) * (isNaN(p) ? 0 : p);
        subtotal += amt;
        r.querySelector('.invoice-amt').textContent = fmt(amt);
    });
    var taxPct = parseFloat(document.getElementById('inv-tax').value || 0);
    if (isNaN(taxPct)) taxPct = 0;
    var tax = subtotal * taxPct / 100;
    var total = subtotal + tax;

    var html = '<div class="inv-sum-row"><span>Subtotal</span><span>' + fmt(subtotal) + '</span></div>';
    if (taxPct > 0) html += '<div class="inv-sum-row"><span>Tax (' + taxPct + '%)</span><span>' + fmt(tax) + '</span></div>';
    html += '<div class="inv-sum-row inv-sum-total"><span>Total</span><span>' + fmt(total) + '</span></div>';
    document.getElementById('invoice-summary').innerHTML = html;
}

function initInvoiceTab() {
    if (!document.querySelectorAll('#invoice-items .invoice-row').length) {
        addInvoiceItem();
        addInvoiceItem();
    } else {
        recalcInvoice();
    }
}

function invoiceBeforeSubmit() {
    var ok = false;
    document.querySelectorAll('#invoice-items [name="item_desc[]"]').forEach(function (el) {
        if (el.value.trim()) ok = true;
    });
    if (!ok) { alert('Add at least one line item with a description.'); return false; }
    return true;
}

// ── On page load ──────────────────────────────────────────────────────────
(function init() {
    loadDashboard();
    rebuildDropdowns();
    loadEmployeeAdvances().then(function() {
        renderEmployeeList();
    });
    onActivityTypeChange();
})();

// ── Outstanding employee advances (per name) ──────────────────────────────
window.advancesByEmployee = {};
function loadEmployeeAdvances() {
    return fetch('reports-api.php?type=employee_advances')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            window.advancesByEmployee = {};
            (d.people || []).forEach(function(p) {
                window.advancesByEmployee[p.counterparty] = p.outstanding;
            });
        })
        .catch(function() { window.advancesByEmployee = {}; });
}

// ── Give Advance form ─────────────────────────────────────────────────────
function openAdvanceForm(employeeName) {
    var card = document.getElementById('advance-form-card');
    document.getElementById('adv-employee-name').value = employeeName;
    document.getElementById('advance-form-title').textContent = 'Give Advance — ' + employeeName;
    document.getElementById('adv-amount').value = '';
    document.getElementById('adv-note').value   = '';
    // Populate source-bank dropdown with all bank/wallet/cash accounts.
    fetch('accounts-api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var sel = document.getElementById('adv-source');
            sel.innerHTML = '';
            (d.accounts || []).forEach(function(a) {
                if (a.name.toLowerCase() === 'employee advances'
                 || a.name.toLowerCase() === 'loans receivable') return;
                sel.add(new Option(a.name + ' (' + a.currency + ')', a.id));
            });
            // Default: first account that contains "HBL" or "Erika" if present.
            var hbl = (d.accounts || []).find(function(a) {
                return /hbl.*erika|erika.*hbl/i.test(a.name);
            });
            if (hbl) sel.value = hbl.id;
        });
    card.style.display = 'block';
    card.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeAdvanceForm() {
    document.getElementById('advance-form-card').style.display = 'none';
}

function submitAdvance(e) {
    e.preventDefault();
    var name   = document.getElementById('adv-employee-name').value;
    var amount = parseFloat(document.getElementById('adv-amount').value);
    var srcId  = document.getElementById('adv-source').value;
    var date   = document.getElementById('adv-date').value;
    var note   = document.getElementById('adv-note').value.trim();

    if (!name || !srcId || !(amount > 0)) {
        showAdvanceAlert('Pick a source bank and a positive amount.', false);
        return;
    }

    // Find Employee Advances account by name (case-insensitive).
    fetch('accounts-api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var dst = (d.accounts || []).find(function(a) {
                return a.name.toLowerCase() === 'employee advances';
            });
            if (!dst) {
                showAdvanceAlert(
                    'Employee Advances account is missing. Add a cash-type account named "Employee Advances" in Setup → Accounts.',
                    false
                );
                return;
            }
            // Find Erika Media book id (the business book).
            return fetch('books-api.php').then(function(r) { return r.json(); }).then(function(bd) {
                var erika = (bd.books || []).find(function(b) {
                    return /erika/i.test(b.name);
                }) || (bd.books || [])[0];
                if (!erika) {
                    showAdvanceAlert('No book found. Set up Erika Media book first.', false);
                    return;
                }
                return apiPost('finances-api.php', {
                    action:         'transfer',
                    book_id:        erika.id,
                    date:           date,
                    amount:         amount,
                    src_account_id: srcId,
                    dst_account_id: dst.id,
                    counterparty:   name,
                    description:    note ? ('Advance: ' + note) : 'Advance to ' + name,
                });
            }).then(function(r) {
                if (!r) return;
                if (r.error) { showAdvanceAlert(r.error, false); return; }
                showAdvanceAlert('Advance of Rs ' + amount.toLocaleString() + ' recorded for ' + name + '.', true);
                closeAdvanceForm();
                loadEmployeeAdvances().then(renderEmployeeList);
            });
        });
}

function showAdvanceAlert(msg, ok) {
    var el = document.getElementById('advance-alert');
    el.className = 'team-alert ' + (ok ? 'success' : 'error');
    el.textContent = msg;
    el.style.display = 'block';
    clearTimeout(el._t);
    el._t = setTimeout(function() { el.style.display = 'none'; }, 4000);
}

// ── Rebuild all dropdowns from teamMembers ────────────────────────────────
function rebuildDropdowns() {
    // Employee name selects
    ['offer-name', 'payslip-name', 'act-employee', 'filter-employee'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        var cur = sel.value;
        var firstOption = (id === 'filter-employee') ? '<option value="">All employees</option>'
                                                     : '<option value="">— Select Employee —</option>';
        sel.innerHTML = firstOption;
        teamMembers.forEach(function(m) { sel.add(new Option(m.name, m.name)); });
        if (cur) sel.value = cur;
    });

    // Department selects (fixed list across the dashboard)
    ['emp-designation', 'offer-position', 'payslip-designation'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        var cur = sel.value;
        sel.innerHTML = '<option value="">— Select Department —</option>';
        DEPARTMENTS.forEach(function(d) { sel.add(new Option(d, d)); });
        // Keep legacy off-list values so editing existing records doesn't blank the field.
        if (cur && DEPARTMENTS.indexOf(cur) === -1) sel.add(new Option(cur, cur));
        if (cur) sel.value = cur;
    });
}

// ── Auto-fill from selected employee profile ──────────────────────────────
function syncFromProfile(form) {
    var nameEl = document.getElementById(form + '-name');
    var member = teamMembers.find(function(m) { return m.name === nameEl.value; });
    if (!member) return;

    if (form === 'offer') {
        document.getElementById('offer-position').value  = member.designation || '';
        document.getElementById('offer-basic').value     = member.basic_salary || '';
        document.getElementById('offer-allowance').value = member.allowance    || 5000;
    } else if (form === 'payslip') {
        document.getElementById('payslip-designation').value = member.designation || '';
        document.getElementById('ps-basic').value     = member.basic_salary       || '';
        document.getElementById('ps-allowance').value = member.allowance          || 0;
        document.getElementById('ps-bonus').value     = member.punctuality_bonus  || 0;
        document.getElementById('ps-pf').value        = member.provident_fund     || 0;
        document.getElementById('ps-eobi').value      = member.eobi               || 0;
        document.getElementById('ps-pt').value        = member.professional_tax   || 0;
        // Reset activity-derived fields when employee changes
        document.getElementById('ps-commission').value = 0;
        document.getElementById('ps-penalty').value    = 0;
        document.getElementById('paid-activity-ids').value = '';
        clearAutofillStatus();

        // Auto-fill the Loan deduction with this employee's outstanding advance.
        var outstanding = (window.advancesByEmployee || {})[member.name] || 0;
        document.getElementById('ps-loan').value = outstanding > 0 ? Math.round(outstanding) : 0;

        // Pro-rate basic salary for the joining month (or zero if pay period is before joining).
        applyProRatedBasic();
        showPayslipHints(member, outstanding);
    }
}

// Recalculate pro-rated basic salary based on joining_date + selected pay period.
function applyProRatedBasic() {
    var nameEl = document.getElementById('payslip-name');
    var member = teamMembers.find(function(m) { return m.name === nameEl.value; });
    if (!member) return;

    var period = document.getElementById('payslip-period').value;  // YYYY-MM
    if (!period) return;
    var monthlyBasic = parseInt(member.basic_salary || 0, 10);
    if (!monthlyBasic) return;
    var joining = (member.joining_date || '').trim();
    if (!joining) {
        document.getElementById('ps-basic').value = monthlyBasic;
        return;
    }

    var jd = new Date(joining + 'T00:00:00');
    var pYear  = parseInt(period.slice(0, 4), 10);
    var pMonth = parseInt(period.slice(5, 7), 10);  // 1-12
    var jYear  = jd.getFullYear();
    var jMonth = jd.getMonth() + 1;

    if (pYear < jYear || (pYear === jYear && pMonth < jMonth)) {
        // Period is BEFORE joining — employee wasn't here, salary = 0.
        document.getElementById('ps-basic').value = 0;
        return;
    }
    if (pYear === jYear && pMonth === jMonth) {
        // Joining month — pro-rate based on calendar days.
        var totalDays  = new Date(pYear, pMonth, 0).getDate();
        var daysWorked = totalDays - jd.getDate() + 1;
        var prorated   = Math.round((monthlyBasic * daysWorked) / totalDays);
        document.getElementById('ps-basic').value = prorated;
        return;
    }
    // Any later month — full salary.
    document.getElementById('ps-basic').value = monthlyBasic;
}

function showPayslipHints(member, outstandingAdvance) {
    var holder = document.getElementById('payslip-hints');
    if (!holder) return;
    var notes = [];
    var period = document.getElementById('payslip-period').value;
    if (member.joining_date && period) {
        var jd = new Date(member.joining_date + 'T00:00:00');
        var pYear  = parseInt(period.slice(0, 4), 10);
        var pMonth = parseInt(period.slice(5, 7), 10);
        if (pYear === jd.getFullYear() && pMonth === (jd.getMonth() + 1)) {
            var total = new Date(pYear, pMonth, 0).getDate();
            var worked = total - jd.getDate() + 1;
            notes.push('<span class="hint-prorate">Pro-rated for ' + worked + ' / ' + total + ' days (joined ' + member.joining_date + ').</span>');
        } else if (pYear < jd.getFullYear() || (pYear === jd.getFullYear() && pMonth < jd.getMonth() + 1)) {
            notes.push('<span class="hint-warn">Pay period is before ' + member.name + '\'s joining date (' + member.joining_date + ').</span>');
        }
    }
    if (outstandingAdvance > 0) {
        notes.push('<span class="hint-advance">Outstanding advance auto-deducted: <strong>Rs ' + Math.round(outstandingAdvance).toLocaleString() + '</strong> (clear the Loan field to skip).</span>');
    }
    holder.innerHTML = notes.join('<br>');
    holder.style.display = notes.length ? 'block' : 'none';
}

// ── Auto-fill payslip from activity log ───────────────────────────────────
function autoFillPayslip() {
    var emp    = document.getElementById('payslip-name').value;
    var month  = document.getElementById('payslip-period').value;
    if (!emp)   { setAutofillStatus('Pick an employee first.', 'error'); return; }
    if (!month) { setAutofillStatus('Pick a pay period first.', 'error'); return; }

    setAutofillStatus('Loading…', '');

    var url = 'activity-api.php?employee=' + encodeURIComponent(emp)
            + '&month=' + encodeURIComponent(month)
            + '&unpaid_only=1';
    fetch(url, { headers: { 'X-CSRF-Token': CSRF } })
        .then(function (r) { return r.json(); })
        .then(function (entries) {
            if (!Array.isArray(entries)) {
                setAutofillStatus('Could not load activity.', 'error');
                return;
            }
            var commission = 0, penalty = 0, bonus = 0;
            var counts = { interview: 0, placement: 0, penalty: 0, bonus: 0 };
            var ids = [];
            entries.forEach(function (e) {
                ids.push(e.id);
                counts[e.type]++;
                if (e.type === 'interview' || e.type === 'placement') commission += e.amount;
                else if (e.type === 'penalty') penalty += e.amount;
                else if (e.type === 'bonus')   bonus   += e.amount;
            });

            // Add to existing values (don't clobber user manual entries — replace commission/penalty since they're activity-derived)
            document.getElementById('ps-commission').value = commission;
            document.getElementById('ps-penalty').value    = penalty;
            // Bonus adds on top of punctuality bonus (which came from profile)
            var existingBonus = parseInt(document.getElementById('ps-bonus').value || 0, 10);
            document.getElementById('ps-bonus').value = existingBonus + bonus;
            document.getElementById('paid-activity-ids').value = ids.join(',');

            if (entries.length === 0) {
                setAutofillStatus('No unpaid activity found for ' + emp + ' in ' + month + '.', 'warn');
            } else {
                var msg = 'Pulled ' + counts.interview + ' interviews, ' + counts.placement
                        + ' placements, ' + counts.penalty + ' penalties, ' + counts.bonus + ' bonuses.';
                setAutofillStatus(msg, 'success');
            }
        })
        .catch(function () { setAutofillStatus('Could not load activity.', 'error'); });
}

function setAutofillStatus(msg, type) {
    var el = document.getElementById('autofill-status');
    el.textContent = msg;
    el.className = 'autofill-status ' + (type || '');
}
function clearAutofillStatus() { setAutofillStatus('', ''); }

// ── Activity form: type-driven field visibility ───────────────────────────
function onActivityTypeChange() {
    var type = document.getElementById('act-type').value;
    var show = function (id, on) { document.getElementById(id).style.display = on ? '' : 'none'; };
    var amountInput = document.getElementById('act-amount');

    show('act-candidate-group', type === 'interview' || type === 'placement');
    show('act-client-group',    type === 'placement');
    show('act-reason-group',    type === 'penalty' || type === 'bonus');
    show('act-amount-group',    type !== 'interview');

    amountInput.required = (type !== 'interview');
    if (type === 'interview') amountInput.value = '';
}

// ── Add activity entry ────────────────────────────────────────────────────
function addActivity(e) {
    e.preventDefault();
    var type      = document.getElementById('act-type').value;
    var employee  = document.getElementById('act-employee').value;
    var date      = document.getElementById('act-date').value;
    var candidate = document.getElementById('act-candidate').value.trim();
    var client    = document.getElementById('act-client').value.trim();
    var reason    = document.getElementById('act-reason').value.trim();
    var amount    = parseInt(document.getElementById('act-amount').value || 0, 10);

    if (!employee || !date) {
        showActivityAlert('error', 'Employee and date are required.');
        return;
    }

    apiPost('activity-api.php', {
        action:    'add',
        type:      type,
        employee:  employee,
        date:      date,
        candidate: candidate,
        client:    client,
        reason:    reason,
        amount:    amount,
    }).then(function (data) {
        if (data.error) { showActivityAlert('error', data.error); return; }
        showActivityAlert('success', 'Logged: ' + type + ' for ' + employee + '.');
        // Reset form except date and type
        document.getElementById('act-candidate').value = '';
        document.getElementById('act-client').value    = '';
        document.getElementById('act-reason').value    = '';
        document.getElementById('act-amount').value    = '';
        loadActivityList();
    }).catch(function () { showActivityAlert('error', 'Could not save. Please try again.'); });
}

// ── Load activity list (with filters) ─────────────────────────────────────
function loadActivityList() {
    var employee = document.getElementById('filter-employee').value;
    var month    = document.getElementById('filter-month').value;
    var status   = document.getElementById('filter-status').value;

    var url = 'activity-api.php?';
    if (employee) url += 'employee=' + encodeURIComponent(employee) + '&';
    if (month)    url += 'month=' + encodeURIComponent(month) + '&';
    if (status === 'unpaid') url += 'unpaid_only=1&';

    fetch(url, { headers: { 'X-CSRF-Token': CSRF } })
        .then(function (r) { return r.json(); })
        .then(function (entries) {
            if (status === 'paid') entries = entries.filter(function (e) { return e.paid_in; });
            renderActivityList(entries);
        })
        .catch(function () {
            document.getElementById('activity-list').innerHTML =
                '<p class="emp-empty">Could not load activity.</p>';
        });
}

function renderActivityList(entries) {
    var el = document.getElementById('activity-list');
    if (!entries || entries.length === 0) {
        el.innerHTML = '<p class="emp-empty">No activity entries match the filters.</p>';
        return;
    }
    var typeLabels = { interview: 'Interview', placement: 'Placement', penalty: 'Penalty', bonus: 'Bonus' };
    var typeClass  = { interview: 'badge-interview', placement: 'badge-placement', penalty: 'badge-penalty', bonus: 'badge-bonus' };
    var html = '<table class="emp-table">'
             + '<thead><tr><th>Type</th><th>Date</th><th>Employee</th><th>Details</th><th>Amount</th><th>Status</th><th></th></tr></thead>'
             + '<tbody>';
    entries.forEach(function (e) {
        var details = '';
        if (e.candidate) details += esc(e.candidate);
        if (e.client)    details += (details ? ' &bull; ' : '') + esc(e.client);
        if (e.reason)    details += (details ? ' &bull; ' : '') + esc(e.reason);
        if (!details)    details = '<span style="color:#999">—</span>';

        var status = e.paid_in
            ? '<span class="paid-badge">Paid ' + esc(fmtMonth(e.paid_in)) + '</span>'
            : '<span class="unpaid-badge">Unpaid</span>';

        html += '<tr>'
              + '<td><span class="doc-badge ' + typeClass[e.type] + '">' + typeLabels[e.type] + '</span></td>'
              + '<td>' + esc(e.date) + '</td>'
              + '<td>' + esc(e.employee) + '</td>'
              + '<td>' + details + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(e.amount)) + '</td>'
              + '<td>' + status + '</td>'
              + '<td><button class="btn-delete" data-id="' + esc(e.id) + '" onclick="deleteActivity(this.dataset.id)">Delete</button></td>'
              + '</tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function deleteActivity(id) {
    if (!confirm('Delete this activity entry?')) return;
    apiPost('activity-api.php', { action: 'delete', id: id })
        .then(function (data) {
            if (data.error) { showActivityAlert('error', data.error); return; }
            loadActivityList();
        })
        .catch(function () { showActivityAlert('error', 'Could not delete.'); });
}

function showActivityAlert(type, msg) {
    var el = document.getElementById('activity-alert');
    el.className = 'team-alert ' + type;
    el.textContent = msg;
    el.style.display = 'block';
    clearTimeout(el._timer);
    el._timer = setTimeout(function() { el.style.display = 'none'; }, 3500);
}

// ── Render employee list ──────────────────────────────────────────────────
function renderEmployeeList() {
    var container = document.getElementById('employee-list');
    if (!container) return;

    if (teamMembers.length === 0) {
        container.innerHTML = '<p class="emp-empty">No team members yet. Add one above.</p>';
        return;
    }

    var advBy = window.advancesByEmployee || {};
    var html = '<table class="emp-table">'
             + '<thead><tr><th>Name</th><th>Department</th><th>Joined</th><th>Basic</th><th>Allowance</th>'
             + '<th>Punctuality</th><th>Outstanding advance</th><th></th></tr></thead><tbody>';

    teamMembers.forEach(function(m) {
        var advance = advBy[m.name] || 0;
        var advCell = advance > 0
            ? '<strong style="color: var(--warning)">' + esc(numFmt(advance)) + '</strong>'
            : '<span class="muted">—</span>';
        html += '<tr data-emp="' + esc(m.name) + '">'
              + '<td>' + esc(m.name) + '</td>'
              + '<td>' + esc(m.designation) + '</td>'
              + '<td style="white-space:nowrap; color: var(--text-muted)">' + esc(m.joining_date || '—') + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(m.basic_salary || 0)) + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(m.allowance    || 0)) + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(m.punctuality_bonus || 0)) + '</td>'
              + '<td style="text-align:right">' + advCell + '</td>'
              + '<td style="white-space:nowrap">'
              + '<button class="btn-edit"   data-name="' + esc(m.name) + '" onclick="startEdit(this.dataset.name)">Edit</button>'
              + '<button class="btn-edit"   data-name="' + esc(m.name) + '" onclick="openAdvanceForm(this.dataset.name)" style="background: var(--warning-soft); color: var(--warning);">Give Advance</button>'
              + '<button class="btn-delete" data-name="' + esc(m.name) + '" onclick="deleteEmployee(this.dataset.name)">Remove</button>'
              + '</td></tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
}

// ── Edit employee: populate form ──────────────────────────────────────────
function startEdit(name) {
    var m = teamMembers.find(function (x) { return x.name === name; });
    if (!m) return;
    document.getElementById('emp-form-title').textContent = 'Edit Team Member: ' + name;
    document.getElementById('emp-original-name').value    = m.name;
    document.getElementById('emp-name').value             = m.name;
    document.getElementById('emp-designation').value      = m.designation;
    document.getElementById('emp-joining-date').value     = m.joining_date       || '';
    document.getElementById('emp-basic').value            = m.basic_salary       || 0;
    document.getElementById('emp-allowance').value        = m.allowance          || 0;
    document.getElementById('emp-punctuality').value      = m.punctuality_bonus  || 0;
    document.getElementById('emp-pf').value               = m.provident_fund     || 0;
    document.getElementById('emp-eobi').value             = m.eobi               || 0;
    document.getElementById('emp-pt').value               = m.professional_tax   || 0;
    document.getElementById('emp-submit-btn').lastChild.textContent = ' Save Changes';
    document.getElementById('emp-cancel-btn').style.display = 'inline-flex';
    // Open over the list instead of scrolling up to the form.
    openCardModal('emp-form-card', findRowByEmployee(name));
}

// Matching on the dataset rather than building a `tr[data-emp="…"]` selector:
// a name containing a quote or a backslash would break the selector, and staff
// names are not something this code gets to make assumptions about.
function findRowByEmployee(name) {
    var rows = document.querySelectorAll('#tab-team tr[data-emp]');
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].dataset.emp === name) return rows[i];
    }
    return null;
}

function cancelEmpEdit() {
    closeCardModal();
    document.getElementById('emp-form-title').textContent = 'Add Team Member';
    document.getElementById('emp-form').reset();
    document.getElementById('emp-original-name').value = '';
    document.getElementById('emp-allowance').value = 5000;
    document.getElementById('emp-punctuality').value = 5000;
    document.getElementById('emp-eobi').value = 370;
    document.getElementById('emp-submit-btn').lastChild.textContent = ' Add to Team';
    document.getElementById('emp-cancel-btn').style.display = 'none';
}

// ── Save (add or edit) employee ───────────────────────────────────────────
function saveEmployee(e) {
    e.preventDefault();
    var original = document.getElementById('emp-original-name').value;
    var payload = {
        action:            original ? 'edit' : 'add',
        original_name:     original,
        name:              document.getElementById('emp-name').value.trim(),
        designation:       document.getElementById('emp-designation').value.trim(),
        joining_date:      document.getElementById('emp-joining-date').value || '',
        basic_salary:      parseInt(document.getElementById('emp-basic').value       || 0, 10),
        allowance:         parseInt(document.getElementById('emp-allowance').value   || 0, 10),
        punctuality_bonus: parseInt(document.getElementById('emp-punctuality').value || 0, 10),
        provident_fund:    parseInt(document.getElementById('emp-pf').value          || 0, 10),
        eobi:              parseInt(document.getElementById('emp-eobi').value        || 0, 10),
        professional_tax:  parseInt(document.getElementById('emp-pt').value          || 0, 10),
    };

    if (!payload.name || !payload.designation) {
        showAlert('error', 'Name and department are required.');
        return;
    }

    apiPost('employees-api.php', payload).then(function (data) {
        if (data.error) { showAlert('error', data.error); return; }
        teamMembers = data.employees;
        rebuildDropdowns();
        renderEmployeeList();
        showAlert('success', payload.name + (original ? ' updated.' : ' added.'));
        cancelEmpEdit();
    }).catch(function () { showAlert('error', 'Could not save.'); });
}

function deleteEmployee(name) {
    if (!confirm('Remove "' + name + '" from the team list?')) return;
    apiPost('employees-api.php', { action: 'delete', name: name })
        .then(function (data) {
            if (data.error) { showAlert('error', data.error); return; }
            teamMembers = data.employees;
            rebuildDropdowns();
            renderEmployeeList();
            showAlert('success', name + ' removed.');
        }).catch(function () { showAlert('error', 'Could not remove.'); });
}

function showAlert(type, msg) {
    var el = document.getElementById('team-alert');
    el.className = 'team-alert ' + type;
    el.textContent = msg;
    el.style.display = 'block';
    clearTimeout(el._timer);
    el._timer = setTimeout(function() { el.style.display = 'none'; }, 3500);
}

// ── Helpers ───────────────────────────────────────────────────────────────
function esc(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

function numFmt(n) {
    n = parseInt(n || 0, 10);
    return n.toLocaleString('en-US');
}

function fmtMonth(ym) {
    if (!ym) return '';
    var d = new Date(ym + '-01');
    return d.toLocaleString('en-US', { month: 'short', year: 'numeric' });
}

// ── History ───────────────────────────────────────────────────────────────
function loadHistoryTab() {
    document.getElementById('history-list').innerHTML = '<p class="emp-empty">Loading&hellip;</p>';
    fetch('history-api.php')
        .then(function(r) { return r.json(); })
        .then(function(data) { renderHistory(data); })
        .catch(function() {
            document.getElementById('history-list').innerHTML =
                '<p class="emp-empty">Could not load history.</p>';
        });
}

var _histRecords = [];

function renderHistory(records) {
    _histRecords = records || [];
    var el = document.getElementById('history-list');
    if (!records || records.length === 0) {
        el.innerHTML = '<p class="emp-empty">No documents generated yet. Generate a payslip or offer letter to see it here.</p>';
        return;
    }
    var html = '<table class="emp-table">'
             + '<thead><tr><th>Type</th><th>Employee</th><th>Period / Date</th><th>Generated</th><th></th></tr></thead>'
             + '<tbody>';
    records.forEach(function(r) {
        var badge = r.type === 'payslip'
            ? '<span class="doc-badge badge-payslip">Payslip</span>'
            : '<span class="doc-badge badge-offer">Offer Letter</span>';
        var period = r.type === 'payslip' ? fmtMonth(r.pay_period) : (r.letter_date || '');
        html += '<tr>'
              + '<td>' + badge + '</td>'
              + '<td>' + esc(r.employee_name || '') + '</td>'
              + '<td>' + esc(period) + '</td>'
              + '<td>' + esc(r.generated_at || '') + '</td>'
              + '<td style="white-space:nowrap">'
              + '<a href="regenerate.php?id=' + encodeURIComponent(r.id) + '" target="_blank" class="btn-regen">Open</a>'
              + (r.type === 'payslip' ? '<button class="btn-edit" data-id="' + esc(r.id) + '" onclick="editPayslip(this.dataset.id)">Edit</button>' : '')
              + '<button class="btn-delete" data-id="' + esc(r.id) + '" onclick="deleteHistory(this.dataset.id)">Delete</button>'
              + '</td>'
              + '</tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function deleteHistory(id) {
    if (!confirm('Remove this entry from history?')) return;
    apiPost('history-api.php', { action: 'delete', id: id })
        .then(function() { loadHistoryTab(); })
        .catch(function() { alert('Could not delete. Please try again.'); });
}

function editPayslip(id) {
    var r = _histRecords.find(function(x) { return x.id === id; });
    if (!r) return;
    showTab('payslip', document.getElementById('nav-payslip'));

    document.getElementById('payslip-name').value = r.employee_name || '';
    syncFromProfile('payslip');  // pulls fresh designation/profile
    document.getElementById('payslip-designation').value = r.designation || '';
    document.getElementById('payslip-period').value = r.pay_period || '';

    setField('basic_salary',    r.basic_salary    || 0);
    setField('allowance',       r.allowance       || 0);
    setField('commission',      r.commission      || 0);
    setField('performer_bonus', r.performer_bonus || 0);
    setField('provident_fund',  r.provident_fund  || 0);
    setField('eobi',            r.eobi            || 0);
    setField('loan',            r.loan            || 0);
    setField('professional_tax',r.professional_tax|| 0);
    setField('absent_late',     r.absent_late     || 0);
    setField('penalty',         r.penalty         || 0);

    document.getElementById('tab-payslip').scrollIntoView({ behavior: 'smooth' });
}

function setField(name, value) {
    var el = document.querySelector('#tab-payslip [name="' + name + '"]');
    if (el) el.value = value;
}

// ── Tab switching ─────────────────────────────────────────────────────────
function showTab(tab, el) {
    closeCardModal();
    document.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.getElementById('tab-' + tab).classList.add('active');
    el.classList.add('active');
    var titles = {
        dashboard:    'Dashboard',
        offer:        'Generate Offer Letter',
        payslip:      'Generate Payslip',
        invoice:      'Create Invoice',
        history:      'Document History',
        activity:     'Activity Log',
        team:         'Manage Team',
        finances:     'Finances',
        accounting:   'Accounting',
        recurring:    'Recurring Entries',
        reports:      'Reports & Analytics',
        payroll:      'Payroll Run',
        'fin-setup':  'Finance Setup',
    };
    document.getElementById('page-title').textContent = titles[tab] || '';
    if (tab === 'dashboard')   loadDashboard();
    if (tab === 'invoice')     initInvoiceTab();
    if (tab === 'history')     loadHistoryTab();
    if (tab === 'activity')    loadActivityList();
    if (tab === 'finances')    loadFinancesTab();
    if (tab === 'accounting')  initAccounting();
    if (tab === 'recurring')   loadRecurring();
    if (tab === 'reports')     loadReports();
    if (tab === 'payroll')     loadPayroll();
    if (tab === 'fin-setup')   loadFinanceSetupTab();
}

// ── Finances (new SQLite-backed system) ───────────────────────────────────
var booksCache       = [];
var accountsCache    = [];   // every account (physical containers — not book-scoped)
var categoriesCache  = [];   // categories visible to current book (or shared)
var currentBook      = null; // selected book id
var currentSub       = 'entries';
var txEditingId      = null;
var splitRowSeq      = 0;

function escFin(s) {
    var d = document.createElement('div');
    d.textContent = String(s == null ? '' : s);
    return d.innerHTML;
}

function fmtMoney(amount, currency) {
    var n = Math.round((amount || 0) * 100) / 100;
    var sym = (currency === 'PKR') ? 'Rs. ' : ((currency || '') + ' ');
    return sym + n.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}

function showFinanceAlert(msg, ok) {
    var el = document.getElementById('finance-alert');
    el.className = 'team-alert ' + (ok ? 'success' : 'error');
    el.textContent = msg;
    el.style.display = 'block';
    if (ok) setTimeout(function() { el.style.display = 'none'; }, 4000);
}

// ── Entry point: load books, default selection, then load views ───────────
function loadFinancesTab() {
    return fetch('books-api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            booksCache = d.books || [];
            if (!currentBook && booksCache.length) currentBook = booksCache[0].id;
            renderBookSelector();
            return Promise.all([loadAccounts(), loadCategories()]);
        })
        .then(function() {
            return loadFinancesEntries();
        });
}

function renderBookSelector() {
    var html = booksCache.map(function(b) {
        var cls = (b.id === currentBook) ? 'book-btn active' : 'book-btn';
        return '<button type="button" class="' + cls + '" onclick="selectBook(\'' + b.id + '\')">' +
            escFin(b.name) + '</button>';
    }).join('');
    document.getElementById('book-selector').innerHTML = html;
}

function selectBook(id) {
    if (currentBook === id) return;
    currentBook = id;
    renderBookSelector();
    Promise.all([loadAccounts(), loadCategories()]).then(function() {
        refreshCurrentSub();
    });
}

function loadAccounts() {
    // Accounts are physical (HBL, UBL, Cash…) and shown unfiltered in
    // every form. The book filter only narrows transactions and slices.
    return fetch('accounts-api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            accountsCache = d.accounts || [];
            populateAccountFilter();
            populateAccountSelects();
        });
}

function loadCategories() {
    return fetch('categories-api.php?book_id=' + encodeURIComponent(currentBook))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            categoriesCache = d.categories || [];
            populateCategoryFilter();
            populateCategorySelects();
        });
}

function populateAccountFilter() {
    var sel = document.getElementById('fin-filter-account');
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML = '<option value="">All accounts</option>';
    accountsCache.forEach(function(a) {
        sel.add(new Option(a.name + ' (' + a.currency + ')', a.id));
    });
    sel.value = cur;
}

function populateCategoryFilter() {
    var sel = document.getElementById('fin-filter-category');
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML = '<option value="">All categories</option>';
    categoriesCache.forEach(function(c) {
        sel.add(new Option(c.name + ' [' + c.type + ']', c.id));
    });
    sel.value = cur;
}

function populateAccountSelects() {
    ['tx-account', 'trn-src', 'trn-dst', 'spl-pair-account'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        var cur = sel.value;
        sel.innerHTML = '<option value="">— pick account —</option>';
        accountsCache.forEach(function(a) {
            sel.add(new Option(a.name + ' (' + a.currency + ')', a.id));
        });
        if (cur) sel.value = cur;
    });
}

function populateCategorySelects() {
    var txSel  = document.getElementById('tx-category');
    var pairSel = document.getElementById('spl-pair-category');
    if (txSel) {
        var curT = txSel.value;
        var t = document.getElementById('tx-type').value;
        txSel.innerHTML = '<option value="">— uncategorised —</option>';
        categoriesCache.filter(function(c) { return c.type === t; }).forEach(function(c) {
            txSel.add(new Option(c.name, c.id));
        });
        if (curT) txSel.value = curT;
    }
    if (pairSel) {
        var curP = pairSel.value;
        pairSel.innerHTML = '<option value="">— uncategorised —</option>';
        categoriesCache.filter(function(c) { return c.type === 'expense'; }).forEach(function(c) {
            pairSel.add(new Option(c.name, c.id));
        });
        if (curP) pairSel.value = curP;
    }
}

// ── Sub-tab navigation ────────────────────────────────────────────────────
function showFinSub(name) {
    currentSub = name;
    document.querySelectorAll('#tab-finances .fin-subtab').forEach(function(b) { b.classList.remove('active'); });
    document.querySelector('#tab-finances .fin-subtab[data-sub="' + name + '"]').classList.add('active');
    document.querySelectorAll('#tab-finances .fin-subpanel').forEach(function(p) { p.classList.remove('active'); });
    document.getElementById('fin-sub-' + name).classList.add('active');
    refreshCurrentSub();
}

function refreshCurrentSub() {
    if (currentSub === 'entries')    loadFinancesEntries();
    if (currentSub === 'categories') loadCategoryTotals();
    if (currentSub === 'accounts')   loadAccountBalances();
    if (currentSub === 'salaries')   loadSalaries();
    if (currentSub === 'loans')      loadLoans();
    if (currentSub === 'splits')     loadSplits();
}

// ── Add/Edit transaction form ─────────────────────────────────────────────
function openTxForm(type) {
    closeAllForms();
    document.getElementById('tx-form-card').style.display = 'block';
    document.getElementById('tx-form-title').textContent = (type === 'income') ? 'Add Money In' : 'Add Money Out';
    document.getElementById('tx-type').value = type;
    document.getElementById('tx-date').value = todayISO();
    document.getElementById('tx-amount').value = '';
    document.getElementById('tx-counterparty').value = '';
    document.getElementById('tx-description').value = '';
    txEditingId = null;
    document.getElementById('tx-submit-btn').textContent = 'Save';
    onTxTypeChange();
    openCardModal('tx-form-card');
}

function closeTxForm() {
    closeCardModal();
    document.getElementById('tx-form-card').style.display = 'none';
    txEditingId = null;
}

function onTxTypeChange() {
    populateCategorySelects();
}

function submitTxForm(e) {
    e.preventDefault();
    var payload = {
        action: txEditingId ? 'update' : 'add',
        date:         document.getElementById('tx-date').value,
        book_id:      currentBook,
        type:         document.getElementById('tx-type').value,
        amount:       parseFloat(document.getElementById('tx-amount').value),
        account_id:   document.getElementById('tx-account').value,
        category_id:  document.getElementById('tx-category').value || '',
        counterparty: document.getElementById('tx-counterparty').value.trim(),
        description:  document.getElementById('tx-description').value.trim(),
    };
    if (txEditingId) payload.id = txEditingId;
    if (!payload.account_id) { showFinanceAlert('Pick an account.', false); return; }
    if (!payload.amount || payload.amount <= 0) { showFinanceAlert('Amount must be positive.', false); return; }

    apiPost('finances-api.php', payload).then(function(d) {
        if (d.error) return showFinanceAlert(d.error, false);
        showFinanceAlert(txEditingId ? 'Updated.' : 'Saved.', true);
        closeTxForm();
        refreshCurrentSub();
    });
}

function editTransaction(id) {
    fetch('finances-api.php?include_void=1&book_id=' + encodeURIComponent(currentBook))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var entry = (d.entries || []).find(function(e) { return e.id === id; });
            if (!entry) return showFinanceAlert('Entry not found.', false);
            if (entry.type !== 'income' && entry.type !== 'expense') {
                showFinanceAlert('Transfers and split rows are best edited via Setup → Audit Log. Void and recreate if you must change them.', false);
                return;
            }
            openTxForm(entry.type);
            txEditingId = id;
            document.getElementById('tx-form-title').textContent = 'Edit Transaction';
            document.getElementById('tx-submit-btn').textContent = 'Save Changes';
            document.getElementById('tx-date').value         = entry.date;
            document.getElementById('tx-type').value         = entry.type;
            populateCategorySelects();
            document.getElementById('tx-account').value      = entry.account_id;
            document.getElementById('tx-amount').value       = entry.amount;
            document.getElementById('tx-category').value     = entry.category_id || '';
            document.getElementById('tx-counterparty').value = entry.counterparty || '';
            document.getElementById('tx-description').value  = entry.description  || '';
        });
}

function voidTransaction(id) {
    if (!confirm('Void this entry? It will be hidden from totals but kept for audit. Linked rows (transfer pairs / split children) will be voided too.')) return;
    apiPost('finances-api.php', { action: 'void', id: id, cascade: true }).then(function(d) {
        if (d.error) return showFinanceAlert(d.error, false);
        showFinanceAlert('Voided.', true);
        refreshCurrentSub();
    });
}

// ── Transfer form (within current book) ───────────────────────────────────
function openTransferForm() {
    closeAllForms();
    document.getElementById('transfer-form-card').style.display = 'block';
    document.getElementById('trn-date').value = todayISO();
    document.getElementById('trn-amount').value = '';
    document.getElementById('trn-description').value = '';
    document.getElementById('trn-counterparty').value = '';
    openCardModal('transfer-form-card');
}

function closeTransferForm() {
    closeCardModal();
    document.getElementById('transfer-form-card').style.display = 'none';
}

function submitTransferForm(e) {
    e.preventDefault();
    var payload = {
        action:         'transfer',
        book_id:        currentBook,
        date:           document.getElementById('trn-date').value,
        amount:         parseFloat(document.getElementById('trn-amount').value),
        src_account_id: document.getElementById('trn-src').value,
        dst_account_id: document.getElementById('trn-dst').value,
        counterparty:   document.getElementById('trn-counterparty').value.trim(),
        description:    document.getElementById('trn-description').value.trim(),
    };
    if (!payload.src_account_id || !payload.dst_account_id) {
        showFinanceAlert('Pick both source and destination accounts.', false); return;
    }
    if (payload.src_account_id === payload.dst_account_id) {
        showFinanceAlert('Source and destination must differ.', false); return;
    }
    apiPost('finances-api.php', payload).then(function(d) {
        if (d.error) return showFinanceAlert(d.error, false);
        showFinanceAlert('Transfer saved.', true);
        closeTransferForm();
        refreshCurrentSub();
    });
}

// ── Split form (auto-split, optionally cross-book paired) ─────────────────
function openSplitForm() {
    closeAllForms();
    splitRowSeq = 0;
    document.getElementById('split-form-card').style.display = 'block';
    document.getElementById('spl-date').value = todayISO();
    document.getElementById('spl-total').value = '';
    document.getElementById('spl-counterparty').value = '';
    document.getElementById('spl-description').value = '';
    document.getElementById('spl-pair').checked = false;
    document.getElementById('spl-pair-fields').style.display = 'none';
    var book = booksCache.find(function(b) { return b.id === currentBook; });
    document.getElementById('spl-target-book-label').textContent = book ? book.name : '—';

    populatePairBookSelect();
    resetSplitToDefault();
    openCardModal('split-form-card');
}

function closeSplitForm() {
    closeCardModal();
    document.getElementById('split-form-card').style.display = 'none';
}

function populatePairBookSelect() {
    var sel = document.getElementById('spl-pair-book');
    sel.innerHTML = '';
    booksCache.filter(function(b) { return b.id !== currentBook; }).forEach(function(b) {
        sel.add(new Option(b.name, b.id));
    });
}

function onPairToggle() {
    document.getElementById('spl-pair-fields').style.display =
        document.getElementById('spl-pair').checked ? 'block' : 'none';
}

function resetSplitToDefault() {
    document.getElementById('spl-rows').innerHTML = '';
    splitRowSeq = 0;
    addSplitRow(70, 'Main / spending');
    addSplitRow(10, 'Charity');
    addSplitRow(10, 'Savings');
    addSplitRow(10, 'Investment');
    recalcSplitAmounts();
}

function addSplitRow(percent, hint) {
    splitRowSeq++;
    var i = splitRowSeq;
    var pct = (percent != null) ? percent : 0;
    var html = '<div class="split-row" id="splrow-' + i + '">' +
        '<div class="split-row-num">#' + i + '</div>' +
        '<div class="form-group"><label>%</label>' +
            '<input type="number" class="spl-pct" min="0" max="100" step="0.01" value="' + pct + '" oninput="recalcSplitAmounts()">' +
        '</div>' +
        '<div class="form-group"><label>Amount</label>' +
            '<input type="number" class="spl-amt" min="0" step="0.01" value="0" oninput="onSplitAmountChange(' + i + ')">' +
        '</div>' +
        '<div class="form-group"><label>Account</label>' +
            '<select class="spl-acc"></select>' +
        '</div>' +
        '<div class="form-group"><label>Category</label>' +
            '<select class="spl-cat"></select>' +
        '</div>' +
        '<div class="form-group"><label>Description / Note</label>' +
            '<input type="text" class="spl-desc" maxlength="200" placeholder="' + escFin(hint || '') + '">' +
        '</div>' +
        '<button type="button" class="split-del" onclick="removeSplitRow(' + i + ')" title="Remove">×</button>' +
    '</div>';
    document.getElementById('spl-rows').insertAdjacentHTML('beforeend', html);
    renderSplitDestOptions();
}

function removeSplitRow(i) {
    var el = document.getElementById('splrow-' + i);
    if (el) el.remove();
    recalcSplitAmounts();
}

function renderSplitDestOptions() {
    var cur = document.getElementById('spl-currency').value;
    var accOptions = '<option value="">— pick account —</option>' + accountsCache
        .filter(function(a) { return a.currency === cur; })
        .map(function(a) { return '<option value="' + a.id + '">' + escFin(a.name) + '</option>'; })
        .join('');
    var catOptions = '<option value="">— uncategorised —</option>' + categoriesCache
        .filter(function(c) { return c.type === 'income'; })
        .map(function(c) { return '<option value="' + c.id + '">' + escFin(c.name) + '</option>'; })
        .join('');
    document.querySelectorAll('#spl-rows .spl-acc').forEach(function(s) {
        var v = s.value; s.innerHTML = accOptions; s.value = v;
    });
    document.querySelectorAll('#spl-rows .spl-cat').forEach(function(s) {
        var v = s.value; s.innerHTML = catOptions; s.value = v;
    });
}

function recalcSplitAmounts() {
    var total = parseFloat(document.getElementById('spl-total').value) || 0;
    var rows = document.querySelectorAll('#spl-rows .split-row');
    var sum = 0;
    rows.forEach(function(r) {
        var pct = parseFloat(r.querySelector('.spl-pct').value) || 0;
        var amt = Math.round(total * pct) / 100;
        r.querySelector('.spl-amt').value = amt.toFixed(2);
        sum += amt;
    });
    var sumEl = document.getElementById('spl-sum-display');
    sumEl.textContent = sum.toFixed(2) + ' / ' + total.toFixed(2);
    var warn = document.getElementById('spl-sum-warn');
    if (Math.abs(sum - total) > 0.01) warn.textContent = '  ⚠ doesn\'t add up to total';
    else warn.textContent = '';
}

function onSplitAmountChange(i) {
    var total = parseFloat(document.getElementById('spl-total').value) || 0;
    if (total > 0) {
        var row = document.getElementById('splrow-' + i);
        var amt = parseFloat(row.querySelector('.spl-amt').value) || 0;
        row.querySelector('.spl-pct').value = ((amt / total) * 100).toFixed(2);
    }
    var sum = 0;
    document.querySelectorAll('#spl-rows .spl-amt').forEach(function(el) {
        sum += parseFloat(el.value) || 0;
    });
    document.getElementById('spl-sum-display').textContent = sum.toFixed(2) + ' / ' + total.toFixed(2);
    var warn = document.getElementById('spl-sum-warn');
    if (Math.abs(sum - total) > 0.01) warn.textContent = '  ⚠ doesn\'t add up to total';
    else warn.textContent = '';
}

function submitSplitForm(e) {
    e.preventDefault();
    var total = parseFloat(document.getElementById('spl-total').value) || 0;
    if (total <= 0) { showFinanceAlert('Total must be positive.', false); return; }

    var splits = [];
    var sum = 0;
    var hasError = false;
    document.querySelectorAll('#spl-rows .split-row').forEach(function(r) {
        if (hasError) return;
        var amt   = parseFloat(r.querySelector('.spl-amt').value) || 0;
        var accId = r.querySelector('.spl-acc').value;
        var catId = r.querySelector('.spl-cat').value;
        var desc  = r.querySelector('.spl-desc').value.trim();
        if (amt <= 0)   { showFinanceAlert('Each split must have a positive amount.', false); hasError = true; return; }
        if (!accId)     { showFinanceAlert('Each split needs a destination account.', false); hasError = true; return; }
        sum += amt;
        splits.push({ amount: amt, account_id: accId, category_id: catId, description: desc });
    });
    if (hasError) return;
    if (Math.abs(sum - total) > 0.01) { showFinanceAlert('Splits must add up to the total.', false); return; }
    if (splits.length < 2) { showFinanceAlert('Provide at least two splits.', false); return; }

    var payload = {
        action:       'split',
        book_id:      currentBook,
        date:         document.getElementById('spl-date').value,
        currency:     document.getElementById('spl-currency').value,
        counterparty: document.getElementById('spl-counterparty').value.trim(),
        description:  document.getElementById('spl-description').value.trim(),
        split_type:   'income',
        splits:       splits,
    };
    if (document.getElementById('spl-pair').checked) {
        var pBook = document.getElementById('spl-pair-book').value;
        var pAcc  = document.getElementById('spl-pair-account').value;
        var pCat  = document.getElementById('spl-pair-category').value;
        if (!pBook || !pAcc) { showFinanceAlert('Source book and account are required for the paired entry.', false); return; }
        payload.match_opposite = {
            book_id:     pBook,
            account_id:  pAcc,
            category_id: pCat,
            description: payload.description,
        };
    }

    apiPost('finances-api.php', payload).then(function(d) {
        if (d.error) return showFinanceAlert(d.error, false);
        showFinanceAlert('Split saved (' + splits.length + ' rows).', true);
        closeSplitForm();
        refreshCurrentSub();
    });
}

function closeAllForms() {
    closeTxForm();
    closeTransferForm();
    closeSplitForm();
}

function todayISO() {
    var d = new Date();
    var pad = function(n) { return n < 10 ? '0' + n : '' + n; };
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
}

// ── Entries view ──────────────────────────────────────────────────────────
function loadFinancesEntries() {
    if (!currentBook) return;
    var month   = document.getElementById('fin-filter-month').value;
    var account = document.getElementById('fin-filter-account').value;
    var category = document.getElementById('fin-filter-category').value;
    var q = '?book_id=' + encodeURIComponent(currentBook);
    if (month)    q += '&month=' + encodeURIComponent(month);
    if (account)  q += '&account_id=' + encodeURIComponent(account);
    if (category) q += '&category_id=' + encodeURIComponent(category);

    document.getElementById('fin-export-link').href = 'finances-api.php' + q + '&export=csv';
    document.getElementById('finances-list').innerHTML = '<p class="emp-empty">Loading&hellip;</p>';

    fetch('finances-api.php' + q)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderFinanceSummary(d.totals || {});
            renderFinanceList(d.entries || []);
        })
        .catch(function() {
            document.getElementById('finances-list').innerHTML = '<p class="emp-empty">Could not load entries.</p>';
        });
}

function renderFinanceSummary(totals) {
    var cur = Object.keys(totals)[0] || 'PKR';
    var t = totals[cur] || { income: 0, expense: 0, net: 0 };
    document.getElementById('fin-total-in').textContent  = fmtMoney(t.income, cur);
    document.getElementById('fin-total-out').textContent = fmtMoney(t.expense, cur);
    var net = t.net || 0;
    document.getElementById('fin-total-net').textContent = (net < 0 ? '− ' : '') + fmtMoney(Math.abs(net), cur);
    document.getElementById('fin-net-card').classList.toggle('fin-net-negative', net < 0);
}

function renderFinanceList(entries) {
    if (!entries.length) {
        document.getElementById('finances-list').innerHTML =
            '<p class="emp-empty">No entries yet for this filter. Use the buttons above to add one.</p>';
        return;
    }
    var rows = entries.map(function(e) {
        var sign  = (e.type === 'income' || e.type === 'transfer_in') ? '+' : '−';
        var typeBadge = e.type === 'income'        ? 'In'
                      : e.type === 'expense'       ? 'Out'
                      : e.type === 'transfer_in'   ? 'Trf In'
                      :                              'Trf Out';
        var cls = (e.type === 'income' || e.type === 'transfer_in') ? 'fin-row-in' : 'fin-row-out';
        var meta = '<span class="fin-meta">' + escFin(e.account_name);
        if (e.category_name) meta += ' · ' + escFin(e.category_name);
        if (e.counterparty)  meta += ' · ' + escFin(e.counterparty);
        if (e.split_group_id) meta += ' · split';
        if (e.linked_tx_id)   meta += ' · linked';
        meta += '</span>';
        return '<tr class="' + cls + '">' +
            '<td class="fin-date">' + escFin(e.date) + '</td>' +
            '<td><span class="fin-type-badge fin-type-' + e.type + '">' + typeBadge + '</span></td>' +
            '<td class="fin-desc-cell">' +
                '<div>' + escFin(e.description || '(no description)') + '</div>' +
                meta +
            '</td>' +
            '<td class="num"><strong>' + sign + ' ' + fmtMoney(e.amount, e.currency) + '</strong></td>' +
            '<td class="fin-actions">' +
                '<button class="finance-edit" onclick="editTransaction(\'' + e.id + '\')">Edit</button>' +
                '<button class="finance-del" onclick="voidTransaction(\'' + e.id + '\')">Void</button>' +
            '</td>' +
        '</tr>';
    }).join('');
    document.getElementById('finances-list').innerHTML =
        '<div class="finance-table-wrap"><table class="finance-table">' +
        '<thead><tr><th>Date</th><th>Type</th><th>Description</th><th class="num">Amount</th><th></th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table></div>';
}

function clearFinanceFilter() {
    document.getElementById('fin-filter-month').value    = '';
    document.getElementById('fin-filter-account').value  = '';
    document.getElementById('fin-filter-category').value = '';
    loadFinancesEntries();
}

// ── By-Category running totals ────────────────────────────────────────────
function loadCategoryTotals() {
    fetch('reports-api.php?type=category_totals&book_id=' + encodeURIComponent(currentBook))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderCategoryTotals(d.categories || []);
        });
}

function renderCategoryTotals(cats) {
    if (!cats.length) {
        document.getElementById('cat-totals-list').innerHTML =
            '<p class="emp-empty">No categories yet. Set them up in Finance Setup → Categories.</p>';
        return;
    }
    var income  = cats.filter(function(c) { return c.type === 'income'; });
    var expense = cats.filter(function(c) { return c.type === 'expense'; });

    var sectionHtml = function(title, list) {
        if (!list.length) return '';
        return '<h3 class="cat-section">' + title + '</h3>' + list.map(function(c) {
            var totals = (c.by_currency || []).map(function(b) {
                return '<span class="cat-total">' + fmtMoney(b.total, b.currency) +
                       ' <span class="muted">(' + b.count + ')</span></span>';
            }).join(' ');
            if (!totals) totals = '<span class="muted">No transactions yet</span>';
            return '<div class="cat-row" onclick="filterByCategory(\'' + c.id + '\')">' +
                '<div class="cat-name">' + escFin(c.name) +
                    (c.linked_employee ? ' <span class="muted">· ' + escFin(c.linked_employee) + '</span>' : '') +
                '</div>' +
                '<div class="cat-totals">' + totals + '</div>' +
            '</div>';
        }).join('');
    };
    document.getElementById('cat-totals-list').innerHTML =
        sectionHtml('Expenses', expense) + sectionHtml('Income', income);
}

function filterByCategory(catId) {
    document.getElementById('fin-filter-category').value = catId;
    showFinSub('entries');
}

// ── Account balances ──────────────────────────────────────────────────────
// Each account is a physical container. We show the real-world balance
// PLUS a per-book breakdown so you can see "of which Charity owns 64k".
function loadAccountBalances() {
    var url = 'reports-api.php?type=account_balances';
    if (currentBook) url += '&book_id=' + encodeURIComponent(currentBook);
    fetch(url)
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderAccountBalances(d.accounts || []);
        });
}

function renderAccountBalances(accs) {
    if (!accs.length) {
        document.getElementById('account-balances-list').innerHTML =
            '<p class="emp-empty">No accounts yet. Add some in Finance Setup → Accounts.</p>';
        return;
    }

    var currentBookName = '';
    if (currentBook) {
        var b = booksCache.find(function(b) { return b.id === currentBook; });
        if (b) currentBookName = b.name;
    }

    var html = accs.map(function(a) {
        var byBook = a.by_book || [];
        var sliceLine = '';
        if (currentBook && currentBookName) {
            var slice = (a.book_balance != null) ? a.book_balance : 0;
            var sliceClass = slice < 0 ? ' is-negative' : (slice > 0 ? ' is-positive' : ' is-zero');
            sliceLine = '<div class="acc-slice' + sliceClass + '">' +
                escFin(currentBookName) + '’s share: <strong>' + fmtMoney(slice, a.currency) + '</strong>' +
            '</div>';
        }

        var breakdownHtml = '';
        if (byBook.length > 0) {
            breakdownHtml = '<div class="acc-breakdown">' + byBook.map(function(bb) {
                var cls = bb.balance < 0 ? ' is-negative' : '';
                var isCurrent = currentBook && bb.book_id === currentBook ? ' is-current' : '';
                return '<div class="acc-breakdown-row' + cls + isCurrent + '">' +
                    '<span class="acc-bb-name">' + escFin(bb.book_name) + '</span>' +
                    '<span class="acc-bb-amt">' + fmtMoney(bb.balance, a.currency) + '</span>' +
                '</div>';
            }).join('') + '</div>';
        }

        return '<div class="account-card" onclick="filterByAccount(\'' + a.id + '\')">' +
            '<div class="acc-card-head">' +
                '<span class="acc-name">' + escFin(a.name) + '</span>' +
                '<span class="acc-type-pill">' + escFin(a.type) + '</span>' +
            '</div>' +
            '<div class="acc-balance-label">Physical balance</div>' +
            '<div class="acc-balance">' + fmtMoney(a.balance, a.currency) + '</div>' +
            sliceLine +
            breakdownHtml +
            '<div class="acc-meta">' + a.tx_count + ' tx · click to filter</div>' +
        '</div>';
    }).join('');
    document.getElementById('account-balances-list').innerHTML =
        '<div class="acc-help">Each card shows the real-world balance of that account. Pick a book at the top to see its slice. Same bank can hold money for multiple books at once.</div>' +
        '<div class="account-grid">' + html + '</div>';
}

function filterByAccount(accId) {
    document.getElementById('fin-filter-account').value = accId;
    showFinSub('entries');
}

// ── Per-employee salaries ─────────────────────────────────────────────────
function loadSalaries() {
    fetch('reports-api.php?type=employee_salaries')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderSalaries(d.employees || []);
        });
}

function renderSalaries(emps) {
    if (!emps.length) {
        document.getElementById('salaries-list').innerHTML =
            '<p class="emp-empty">No employee-linked categories used yet. Create them in Finance Setup → Categories.</p>';
        return;
    }
    var rows = emps.map(function(e) {
        var totals = (e.by_currency || []).map(function(b) {
            return fmtMoney(b.total, b.currency) + ' <span class="muted">(' + b.count + ')</span>';
        }).join(' · ');
        return '<tr><td>' + escFin(e.employee) + '</td>' +
               '<td>' + totals + '</td>' +
               '<td>' + escFin(e.last_paid || '') + '</td></tr>';
    }).join('');
    document.getElementById('salaries-list').innerHTML =
        '<table class="finance-table"><thead><tr><th>Employee</th><th>All-time paid</th><th>Last paid</th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table>';
}

// ── Loans view ────────────────────────────────────────────────────────────
// Pivots transactions on the Loans Receivable account by counterparty.
// Per person:
//   lent      = sum of transfer_out from your banks INTO Loans Receivable
//   repaid    = sum of transfer_in BACK from Loans Receivable to your banks
//   writtenOff= sum of expense from Loans Receivable (Bad Debt Write-off)
//   outstanding = lent - repaid - writtenOff
function loadLoans() {
    fetch('reports-api.php?type=loans_summary')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderLoans(d);
        })
        .catch(function() {
            document.getElementById('loans-list').innerHTML =
                '<p class="emp-empty">Could not load loans.</p>';
        });
}

function renderLoans(d) {
    var holder = document.getElementById('loans-list');
    if (!d || !d.account) {
        holder.innerHTML =
            '<p class="emp-empty">No <strong>Loans Receivable</strong> account exists yet. Create one in Setup &rarr; Accounts (any cash-type account named "Loans Receivable") and then record loans by transferring from your bank to it.</p>';
        return;
    }
    var people = d.people || [];
    var total = d.totals || { lent: 0, repaid: 0, written_off: 0, outstanding: 0 };
    var cur = d.currency || 'PKR';

    var summary =
        '<div class="loans-summary">' +
            '<div class="loans-stat"><span class="loans-stat-label">Total lent (lifetime)</span>' +
                '<span class="loans-stat-value">' + fmtMoney(total.lent, cur) + '</span></div>' +
            '<div class="loans-stat"><span class="loans-stat-label">Repaid</span>' +
                '<span class="loans-stat-value is-good">' + fmtMoney(total.repaid, cur) + '</span></div>' +
            '<div class="loans-stat"><span class="loans-stat-label">Written off</span>' +
                '<span class="loans-stat-value is-bad">' + fmtMoney(total.written_off, cur) + '</span></div>' +
            '<div class="loans-stat is-headline"><span class="loans-stat-label">Outstanding</span>' +
                '<span class="loans-stat-value">' + fmtMoney(total.outstanding, cur) + '</span></div>' +
        '</div>';

    var rows = '';
    if (!people.length) {
        rows = '<p class="emp-empty">No loans on record yet.</p>';
    } else {
        rows = '<table class="finance-table"><thead><tr>' +
            '<th>Person</th><th class="num">Lent</th><th class="num">Repaid</th>' +
            '<th class="num">Written off</th><th class="num">Outstanding</th><th>Status</th>' +
            '</tr></thead><tbody>' +
            people.map(function(p) {
                var status = p.outstanding > 0 ? 'open'
                           : p.written_off > 0 && p.repaid + p.written_off >= p.lent - 0.01 ? 'written-off'
                           : 'settled';
                var statusBadge = '<span class="loan-status loan-' + status + '">' + status.replace('-', ' ') + '</span>';
                return '<tr>' +
                    '<td><strong>' + escFin(p.counterparty || '(no name)') + '</strong></td>' +
                    '<td class="num">' + fmtMoney(p.lent, cur) + '</td>' +
                    '<td class="num">' + fmtMoney(p.repaid, cur) + '</td>' +
                    '<td class="num">' + fmtMoney(p.written_off, cur) + '</td>' +
                    '<td class="num"><strong>' + fmtMoney(p.outstanding, cur) + '</strong></td>' +
                    '<td>' + statusBadge + '</td>' +
                '</tr>';
            }).join('') +
            '</tbody></table>';
    }
    holder.innerHTML = summary + rows;
}

// ── Splits view ───────────────────────────────────────────────────────────
function loadSplits() {
    fetch('reports-api.php?type=split_groups&book_id=' + encodeURIComponent(currentBook))
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderSplits(d.groups || []);
        });
}

function renderSplits(groups) {
    if (!groups.length) {
        document.getElementById('splits-list').innerHTML =
            '<p class="emp-empty">No split events yet for this book.</p>';
        return;
    }
    var rows = groups.map(function(g) {
        return '<tr onclick="document.getElementById(\'fin-filter-month\').value = \'\'; showFinSub(\'entries\');">' +
            '<td>' + escFin(g.date) + '</td>' +
            '<td>' + fmtMoney(g.total, g.currency) + '</td>' +
            '<td>' + g.legs + '</td>' +
            '<td>' + escFin(g.counterparty || '') + '</td>' +
            '<td>' + escFin(g.description || '') + '</td>' +
        '</tr>';
    }).join('');
    document.getElementById('splits-list').innerHTML =
        '<table class="finance-table"><thead><tr><th>Date</th><th>Total</th><th>Legs</th><th>Counterparty</th><th>Description</th></tr></thead>' +
        '<tbody>' + rows + '</tbody></table>';
}

// ── Company & accounting settings ─────────────────────────────────────────
function loadSettings() {
    return fetch('settings-api.php')
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.error) return;
            applySettings(d);
        })
        .catch(function () {});
}

function applySettings(d) {
    var s = d.settings || {};
    var set = function (id, v) { var e = document.getElementById(id); if (e) e.value = v == null ? '' : v; };
    set('set-company-name',    s.company_name);
    set('set-company-ntn',     s.company_ntn);
    set('set-company-address', s.company_address);
    set('set-fy-month',        s.fiscal_year_start_month || '7');
    set('set-currency',        s.base_currency || 'PKR');

    // Both pickers offer an explicit "decide for me" so the saved value can be
    // cleared, not just changed.
    ['set-payroll-account', 'set-statutory-account'].forEach(function (id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        sel.innerHTML = '<option value="">— pick automatically —</option>'
            + (d.accounts || []).filter(function (a) { return !a.archived; })
                .map(function (a) {
                    return '<option value="' + escFin(a.id) + '">' + escFin(a.name)
                         + ' (' + escFin(a.currency) + ')</option>';
                }).join('');
    });
    set('set-payroll-account',   s.payroll_account_id);
    set('set-statutory-account', s.statutory_account_id);

    var cb = document.getElementById('set-autopost');
    if (cb) cb.checked = (s.payroll_autopost || '1') === '1';
}

function saveSettings(ev) {
    ev.preventDefault();
    var g = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
    apiPost('settings-api.php', {
        action: 'save',
        settings: {
            company_name:            g('set-company-name'),
            company_ntn:             g('set-company-ntn'),
            company_address:         g('set-company-address'),
            fiscal_year_start_month: g('set-fy-month'),
            base_currency:           g('set-currency'),
            payroll_account_id:      g('set-payroll-account'),
            statutory_account_id:    g('set-statutory-account'),
            payroll_autopost:        document.getElementById('set-autopost').checked ? '1' : '0',
        },
    }).then(function (d) {
        if (d.error) return setupAlert('settings-alert', d.error, false);
        var n = (d.changed || []).length;
        setupAlert('settings-alert', n ? ('Saved ' + n + ' change' + (n === 1 ? '' : 's') + '.')
                                       : 'Nothing had changed.', true);
        // The response carries the whole settings payload back, so the form
        // rebinds from what was actually stored rather than what was typed.
        applySettings(d);
    });
}

// ── Finance Setup tab ─────────────────────────────────────────────────────
function loadFinanceSetupTab() {
    loadSettings();
    return fetch('books-api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            booksCache = d.books || [];
            renderSetupBooks();
            populateBookSelectsForSetup();
            return fetch('accounts-api.php?include_archived=1');
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderSetupAccounts(d.accounts || []);
            return fetch('categories-api.php?include_archived=1');
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            renderSetupCategories(d.categories || []);
            populateParentCategorySelect(d.categories || []);
        });
}

function setupAlert(targetId, msg, ok) {
    var el = document.getElementById(targetId);
    if (!el) return;
    el.className = 'team-alert ' + (ok ? 'success' : 'error');
    el.textContent = msg;
    el.style.display = 'block';
    if (ok) setTimeout(function() { el.style.display = 'none'; }, 3000);
}

function populateBookSelectsForSetup() {
    ['newacc-book', 'newcat-book'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        var blank = id === 'newacc-book' ? 'Shared' : 'Any book';
        sel.innerHTML = '<option value="">' + blank + '</option>';
        booksCache.forEach(function(b) { sel.add(new Option(b.name, b.id)); });
    });
}

function populateParentCategorySelect(cats) {
    var sel = document.getElementById('newcat-parent');
    if (!sel) return;
    sel.innerHTML = '<option value="">— top-level —</option>';
    cats.forEach(function(c) { sel.add(new Option(c.name + ' [' + c.type + ']', c.id)); });
}

function renderSetupBooks() {
    if (!booksCache.length) {
        document.getElementById('setup-books-list').innerHTML = '<p class="emp-empty">No books.</p>'; return;
    }
    var html = '<div class="setup-list">' + booksCache.map(function(b) {
        var typeClass = b.type === 'business' ? 'book-type-business' : 'book-type-personal';
        return '<div class="setup-row">' +
            '<div class="setup-row-head">' +
                '<span class="book-type-pill ' + typeClass + '">' + b.type + '</span>' +
                '<span class="setup-row-title">' + escFin(b.name) + '</span>' +
                '<div class="setup-row-actions">' +
                    '<button class="setup-btn" onclick="setupSaveBook(\'' + b.id + '\')">Save</button>' +
                    '<button class="setup-btn-warn" onclick="setupArchiveBook(\'' + b.id + '\', \'' + escFin(b.name).replace(/'/g, "\\'") + '\')">Delete</button>' +
                '</div>' +
            '</div>' +
            '<div class="setup-row-fields">' +
                '<div class="sf-field"><label>Name</label>' +
                    '<input type="text" id="bk-' + b.id + '-name" value="' + escFin(b.name) + '">' +
                '</div>' +
                '<div class="sf-field"><label>Type</label>' +
                    '<select id="bk-' + b.id + '-type">' +
                        '<option value="business"' + (b.type === 'business' ? ' selected' : '') + '>Business</option>' +
                        '<option value="personal"' + (b.type === 'personal' ? ' selected' : '') + '>Personal</option>' +
                    '</select>' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('') + '</div>';
    document.getElementById('setup-books-list').innerHTML = html;
}

function setupCreateBook(e) {
    e.preventDefault();
    var name = document.getElementById('newbook-name').value.trim();
    var type = document.getElementById('newbook-type').value;
    if (!name) return;
    apiPost('books-api.php', { action: 'create', name: name, type: type }).then(function(d) {
        if (d.error) return setupAlert('setup-books-alert', d.error, false);
        document.getElementById('newbook-name').value = '';
        setupAlert('setup-books-alert', 'Book created.', true);
        loadFinanceSetupTab();
    });
}

function setupSaveBook(id) {
    var name = document.getElementById('bk-' + id + '-name').value.trim();
    var type = document.getElementById('bk-' + id + '-type').value;
    apiPost('books-api.php', { action: 'update', id: id, name: name, type: type }).then(function(d) {
        if (d.error) return setupAlert('setup-books-alert', d.error, false);
        setupAlert('setup-books-alert', 'Saved.', true);
    });
}

function setupArchiveBook(id, name) {
    if (!confirm('Delete the book "' + name + '"?\n\n' +
                 'It will be hidden from all lists. If it has any transactions, ' +
                 'the server will refuse and tell you to void or move them first.')) return;
    apiPost('books-api.php', { action: 'archive', id: id, archived: 1 }).then(function(d) {
        if (d.error) return setupAlert('setup-books-alert', d.error, false);
        setupAlert('setup-books-alert', 'Book "' + name + '" deleted.', true);
        loadFinanceSetupTab();
    });
}

function renderSetupAccounts(accs) {
    if (!accs.length) {
        document.getElementById('setup-accounts-list').innerHTML =
            '<p class="emp-empty">No accounts yet. Add one below.</p>';
        return;
    }
    var html = '<div class="setup-list">' + accs.map(function(a) {
        var bookOpts = '<option value="">Shared</option>' + booksCache.map(function(b) {
            return '<option value="' + b.id + '"' + (b.id === a.book_id ? ' selected' : '') + '>' + escFin(b.name) + '</option>';
        }).join('');
        var types = ['bank','cash','wallet','crypto'];
        var typeOpts = types.map(function(t) {
            return '<option value="' + t + '"' + (t === a.type ? ' selected' : '') + '>' + t + '</option>';
        }).join('');
        var currencies = ['PKR','USDT','USD','EUR','AED','GBP'];
        var curOpts = currencies.map(function(c) {
            return '<option value="' + c + '"' + (c === a.currency ? ' selected' : '') + '>' + c + '</option>';
        }).join('');

        var bal      = parseFloat(a.balance || 0);
        var balClass = bal < 0 ? ' is-negative' : '';

        return '<div class="setup-row ' + (a.archived == 1 ? 'archived' : '') + '">' +
            '<div class="setup-row-head">' +
                '<span class="acc-type-pill">' + a.type + '</span>' +
                '<span class="setup-row-title">' + escFin(a.name) + '</span>' +
                '<span class="setup-row-balance' + balClass + '">' + fmtMoney(a.balance, a.currency) + '</span>' +
                '<div class="setup-row-actions">' +
                    '<button class="setup-btn" onclick="setupSaveAccount(\'' + a.id + '\')">Save</button>' +
                    '<button class="setup-btn-warn" onclick="setupArchiveAccount(\'' + a.id + '\', ' + (a.archived == 1 ? '0' : '1') + ')">' +
                        (a.archived == 1 ? 'Unarchive' : 'Archive') +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="setup-row-fields">' +
                '<div class="sf-field"><label>Name</label>' +
                    '<input type="text" id="ac-' + a.id + '-name" value="' + escFin(a.name) + '">' +
                '</div>' +
                '<div class="sf-field"><label>Type</label>' +
                    '<select id="ac-' + a.id + '-type">' + typeOpts + '</select>' +
                '</div>' +
                '<div class="sf-field"><label>Currency</label>' +
                    '<select id="ac-' + a.id + '-cur">' + curOpts + '</select>' +
                '</div>' +
                '<div class="sf-field"><label>Primary book (optional)</label>' +
                    '<select id="ac-' + a.id + '-book">' + bookOpts + '</select>' +
                '</div>' +
                '<div class="sf-field"><label>Opening balance</label>' +
                    '<input type="number" id="ac-' + a.id + '-opening" value="' + (a.opening_balance || 0) + '" step="0.01">' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('') + '</div>';
    document.getElementById('setup-accounts-list').innerHTML = html;
}

function setupCreateAccount(e) {
    e.preventDefault();
    var payload = {
        action:          'create',
        name:            document.getElementById('newacc-name').value.trim(),
        type:            document.getElementById('newacc-type').value,
        currency:        document.getElementById('newacc-currency').value,
        book_id:         document.getElementById('newacc-book').value,
        opening_balance: parseFloat(document.getElementById('newacc-opening').value) || 0,
        notes:           document.getElementById('newacc-notes').value.trim(),
    };
    apiPost('accounts-api.php', payload).then(function(d) {
        if (d.error) return setupAlert('setup-accounts-alert', d.error, false);
        document.getElementById('newacc-name').value = '';
        document.getElementById('newacc-opening').value = '0';
        document.getElementById('newacc-notes').value = '';
        setupAlert('setup-accounts-alert', 'Account created.', true);
        loadFinanceSetupTab();
    });
}

function setupSaveAccount(id) {
    var payload = {
        action:          'update',
        id:              id,
        name:            document.getElementById('ac-' + id + '-name').value.trim(),
        type:            document.getElementById('ac-' + id + '-type').value,
        currency:        document.getElementById('ac-' + id + '-cur').value,
        book_id:         document.getElementById('ac-' + id + '-book').value,
        opening_balance: parseFloat(document.getElementById('ac-' + id + '-opening').value) || 0,
    };
    apiPost('accounts-api.php', payload).then(function(d) {
        if (d.error) return setupAlert('setup-accounts-alert', d.error, false);
        setupAlert('setup-accounts-alert', 'Saved.', true);
        loadFinanceSetupTab();
    });
}

function setupArchiveAccount(id, flag) {
    apiPost('accounts-api.php', { action: 'archive', id: id, archived: flag }).then(function(d) {
        if (d.error) return setupAlert('setup-accounts-alert', d.error, false);
        loadFinanceSetupTab();
    });
}

function renderSetupCategories(cats) {
    if (!cats.length) {
        document.getElementById('setup-categories-list').innerHTML =
            '<p class="emp-empty">No categories yet. Add some below.</p>';
        return;
    }
    var html = '<div class="setup-list">' + cats.map(function(c) {
        var bookOpts = '<option value="">Any book</option>' + booksCache.map(function(b) {
            return '<option value="' + b.id + '"' + (b.id === c.book_scope ? ' selected' : '') + '>' + escFin(b.name) + '</option>';
        }).join('');
        var parentOpts = '<option value="">— top-level —</option>' + cats
            .filter(function(p) { return p.id !== c.id && p.type === c.type; })
            .map(function(p) {
                return '<option value="' + p.id + '"' + (p.id === c.parent_id ? ' selected' : '') + '>' + escFin(p.name) + '</option>';
            }).join('');
        return '<div class="setup-row ' + (c.archived == 1 ? 'archived' : '') + '">' +
            '<div class="setup-row-head">' +
                '<span class="cat-type-pill cat-type-' + c.type + '">' + c.type + '</span>' +
                '<span class="setup-row-title">' + escFin(c.name) + '</span>' +
                '<span class="setup-row-meta">' + (c.tx_count || 0) + ' tx · ' + fmtMoney(c.tx_total || 0, '') + '</span>' +
                '<div class="setup-row-actions">' +
                    '<button class="setup-btn" onclick="setupSaveCategory(\'' + c.id + '\', \'' + c.type + '\')">Save</button>' +
                    '<button class="setup-btn-warn" onclick="setupArchiveCategory(\'' + c.id + '\', ' + (c.archived == 1 ? '0' : '1') + ')">' +
                        (c.archived == 1 ? 'Unarchive' : 'Archive') +
                    '</button>' +
                '</div>' +
            '</div>' +
            '<div class="setup-row-fields">' +
                '<div class="sf-field"><label>Name</label>' +
                    '<input type="text" id="ct-' + c.id + '-name" value="' + escFin(c.name) + '">' +
                '</div>' +
                '<div class="sf-field"><label>Parent</label>' +
                    '<select id="ct-' + c.id + '-parent">' + parentOpts + '</select>' +
                '</div>' +
                '<div class="sf-field"><label>Book scope</label>' +
                    '<select id="ct-' + c.id + '-book">' + bookOpts + '</select>' +
                '</div>' +
                '<div class="sf-field"><label>Linked employee (optional)</label>' +
                    '<input type="text" id="ct-' + c.id + '-emp" value="' + escFin(c.linked_employee || '') + '" placeholder="employee name">' +
                '</div>' +
            '</div>' +
        '</div>';
    }).join('') + '</div>';
    document.getElementById('setup-categories-list').innerHTML = html;
}

function setupCreateCategory(e) {
    e.preventDefault();
    var payload = {
        action:          'create',
        name:            document.getElementById('newcat-name').value.trim(),
        type:            document.getElementById('newcat-type').value,
        parent_id:       document.getElementById('newcat-parent').value,
        book_scope:      document.getElementById('newcat-book').value,
        linked_employee: document.getElementById('newcat-employee').value.trim(),
    };
    apiPost('categories-api.php', payload).then(function(d) {
        if (d.error) return setupAlert('setup-categories-alert', d.error, false);
        document.getElementById('newcat-name').value = '';
        document.getElementById('newcat-employee').value = '';
        setupAlert('setup-categories-alert', 'Category created.', true);
        loadFinanceSetupTab();
    });
}

function setupSaveCategory(id, type) {
    var payload = {
        action:          'update',
        id:              id,
        name:            document.getElementById('ct-' + id + '-name').value.trim(),
        type:            type,
        parent_id:       document.getElementById('ct-' + id + '-parent').value,
        book_scope:      document.getElementById('ct-' + id + '-book').value,
        linked_employee: document.getElementById('ct-' + id + '-emp').value.trim(),
    };
    apiPost('categories-api.php', payload).then(function(d) {
        if (d.error) return setupAlert('setup-categories-alert', d.error, false);
        setupAlert('setup-categories-alert', 'Saved.', true);
        loadFinanceSetupTab();
    });
}

function setupArchiveCategory(id, flag) {
    apiPost('categories-api.php', { action: 'archive', id: id, archived: flag }).then(function(d) {
        if (d.error) return setupAlert('setup-categories-alert', d.error, false);
        loadFinanceSetupTab();
    });
}

// ── Suggested Setup ───────────────────────────────────────────────
// One-click idempotent seed. Looks at existing books/accounts/categories
// and only creates what's missing (matched by name, case-insensitive).
function seedSuggestedSetup() {
    if (!confirm('Create the suggested set of accounts and categories?\n\n' +
                 'This will skip anything you already have, so it\'s safe to run again. ' +
                 'You can rename or delete anything afterwards.')) return;

    setupAlert('setup-books-alert', 'Setting things up…', true);

    // 1. Make sure required books exist
    var requiredBooks = [
        { name: 'Erika Media', type: 'business' },
        { name: 'Kuldeep',     type: 'personal' },
        { name: 'Charity',     type: 'personal' },
    ];

    fetch('books-api.php')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var existing = (d.books || []).map(function(b) { return (b.name || '').toLowerCase(); });
            // Already-lowercased existing names; create only missing books.
            var toCreate = requiredBooks.filter(function(b) {
                return existing.indexOf(b.name.toLowerCase()) === -1;
            });
            return Promise.all(toCreate.map(function(b) {
                return apiPost('books-api.php', { action: 'create', name: b.name, type: b.type });
            }));
        })
        // 2. Reload books, then create accounts
        .then(function() { return fetch('books-api.php').then(function(r) { return r.json(); }); })
        .then(function(d) {
            var books = d.books || [];
            booksCache = books;
            var bookByName = {};
            books.forEach(function(b) { bookByName[(b.name || '').toLowerCase()] = b.id; });

            // Lookup helpers — find a book by any of several candidate names
            function findBook(/* ...candidates */) {
                for (var i = 0; i < arguments.length; i++) {
                    var id = bookByName[arguments[i].toLowerCase()];
                    if (id) return id;
                }
                return null;
            }

            var biz      = findBook('Erika Media');
            var personal = findBook('Kuldeep', 'Kuldeep (Personal)', 'Personal');
            var charity  = findBook('Charity', 'CHARITY');

            var seedAccounts = [
                // Business
                { name: 'HBL — Erika Media',  type: 'bank',   currency: 'PKR', book_id: biz },
                { name: 'Easypaisa — Erika Media', type: 'wallet', currency: 'PKR', book_id: biz },
                // Personal
                { name: 'HBL — Personal',     type: 'bank',   currency: 'PKR', book_id: personal },
                { name: 'Savings',            type: 'bank',   currency: 'PKR', book_id: personal },
                { name: 'Investments',        type: 'bank',   currency: 'PKR', book_id: personal },
                { name: 'Personal Cash',      type: 'cash',   currency: 'PKR', book_id: personal },
                { name: 'Easypaisa — Personal', type: 'wallet', currency: 'PKR', book_id: personal },
                // Charity
                { name: 'Charity Bank',       type: 'bank',   currency: 'PKR', book_id: charity },
                { name: 'Charity Cash',       type: 'cash',   currency: 'PKR', book_id: charity },
            ].filter(function(a) { return a.book_id; });

            return fetch('accounts-api.php?include_archived=1')
                .then(function(r) { return r.json(); })
                .then(function(ad) {
                    var existingAcc = (ad.accounts || []).map(function(a) { return (a.name || '').toLowerCase(); });
                    var toCreate = seedAccounts.filter(function(a) {
                        return existingAcc.indexOf(a.name.toLowerCase()) === -1;
                    });
                    return Promise.all(toCreate.map(function(a) {
                        return apiPost('accounts-api.php', {
                            action:          'create',
                            name:            a.name,
                            type:            a.type,
                            currency:        a.currency,
                            book_id:         a.book_id,
                            opening_balance: 0,
                            notes:           '',
                        });
                    })).then(function() { return { biz: biz, personal: personal, charity: charity }; });
                });
        })
        // 3. Create categories
        .then(function(ctx) {
            var seedCats = [
                // Income
                { name: 'Recruitment Revenue', type: 'income',  book_scope: ctx.biz },
                { name: 'Salary Received',     type: 'income',  book_scope: ctx.personal },
                { name: 'Donations Received',  type: 'income',  book_scope: ctx.charity },
                // Business expenses
                { name: 'Salaries',            type: 'expense', book_scope: ctx.biz },
                { name: 'Rent',                type: 'expense', book_scope: ctx.biz },
                { name: 'Utilities',           type: 'expense', book_scope: ctx.biz },
                { name: 'Internet & Phone',    type: 'expense', book_scope: ctx.biz },
                { name: 'Office Supplies',     type: 'expense', book_scope: ctx.biz },
                { name: 'Software / SaaS',     type: 'expense', book_scope: ctx.biz },
                { name: 'Marketing',           type: 'expense', book_scope: ctx.biz },
                { name: 'Bank Fees',           type: 'expense', book_scope: null },
                // Personal expenses
                { name: 'Groceries',           type: 'expense', book_scope: ctx.personal },
                { name: 'Transport',           type: 'expense', book_scope: ctx.personal },
                { name: 'Health',              type: 'expense', book_scope: ctx.personal },
                { name: 'Eating Out',          type: 'expense', book_scope: ctx.personal },
                { name: 'Charity Giving',      type: 'expense', book_scope: ctx.personal },
                // Charity expenses
                { name: 'Charity Disbursement', type: 'expense', book_scope: ctx.charity },
            ].filter(function(c) { return c.book_scope !== undefined; });

            return fetch('categories-api.php?include_archived=1')
                .then(function(r) { return r.json(); })
                .then(function(cd) {
                    var existingCats = (cd.categories || []).map(function(c) { return (c.name || '').toLowerCase(); });
                    var toCreate = seedCats.filter(function(c) {
                        return existingCats.indexOf(c.name.toLowerCase()) === -1;
                    });
                    return Promise.all(toCreate.map(function(c) {
                        return apiPost('categories-api.php', {
                            action:          'create',
                            name:            c.name,
                            type:            c.type,
                            parent_id:       '',
                            book_scope:      c.book_scope || '',
                            linked_employee: '',
                        });
                    })).then(function(results) {
                        return { created: results.length };
                    });
                });
        })
        .then(function(result) {
            setupAlert('setup-books-alert',
                'Suggested setup applied. Reloading…', true);
            loadFinanceSetupTab();
        })
        .catch(function(err) {
            setupAlert('setup-books-alert',
                'Something went wrong: ' + (err.message || err), false);
        });
}

function setupSyncEmployeeCategories() {
    var sel = document.getElementById('newcat-parent');
    var parentId = sel ? sel.value : '';
    if (!parentId) {
        var picked = prompt('Paste the parent category ID (an expense category, e.g. "Salaries") to sync employees under:');
        if (!picked) return;
        parentId = picked;
    }
    apiPost('categories-api.php', { action: 'sync_employee_salary_categories', parent_id: parentId }).then(function(d) {
        if (d.error) return setupAlert('setup-categories-alert', d.error, false);
        var msg = d.created.length ? ('Created ' + d.created.length + ' employee categories: ' + d.created.join(', '))
                                   : 'No new categories — all employees already have one.';
        setupAlert('setup-categories-alert', msg, true);
        loadFinanceSetupTab();
    });
}

// ── Accounting ────────────────────────────────────────────────────────────
// Every report on this screen reads the same period, chosen once at the top,
// so switching between the P&L and the trial balance can never quietly compare
// two different spans. The server echoes back the range it actually used and
// that is what gets displayed — the label is never derived from the buttons.

var ACCT_PRESETS = [
    ['this_month',    'This month'],
    ['last_month',    'Last month'],
    ['last_3_months', 'Last 3 months'],
    ['this_quarter',  'This quarter'],
    ['this_fy',       'This FY'],
    ['last_fy',       'Last FY'],
    ['all_time',      'All time'],
];

var acctState = {
    preset:   'this_fy',
    from:     '',
    to:       '',
    book:     'business',
    sub:      'overview',
    started:  false,
    range:    null,
    accounts: [],
};

function acctAlert(msg, ok) {
    var el = document.getElementById('acct-alert');
    el.className = 'team-alert ' + (ok ? 'success' : 'error');
    el.textContent = msg;
    el.style.display = 'block';
    if (ok) setTimeout(function () { el.style.display = 'none'; }, 4000);
}

function initAccounting() {
    if (!acctState.started) {
        var wrap = document.getElementById('acct-presets');
        wrap.innerHTML = ACCT_PRESETS.map(function (p) {
            return '<button type="button" class="range-preset' + (p[0] === acctState.preset ? ' active' : '')
                 + '" data-preset="' + p[0] + '" onclick="acctPreset(\'' + p[0] + '\')">' + escFin(p[1]) + '</button>';
        }).join('');
        acctState.started = true;
    }
    // The ledger and statement views need the account list to build their
    // pickers, so it is fetched once up front rather than mid-render.
    acctLoadAccounts().then(acctReload, acctReload);
}

function acctPreset(preset) {
    acctState.preset = preset;
    document.querySelectorAll('#acct-presets .range-preset').forEach(function (b) {
        b.classList.toggle('active', b.dataset.preset === preset);
    });
    document.getElementById('acct-from').value = '';
    document.getElementById('acct-to').value = '';
    acctReload();
}

// Typing a date means the presets no longer describe what is shown, so they
// stop being highlighted rather than leaving a stale one lit.
function acctCustom() {
    var f = document.getElementById('acct-from').value;
    var t = document.getElementById('acct-to').value;
    if (!f || !t) return;
    acctState.preset = 'custom';
    acctState.from = f;
    acctState.to = t;
    document.querySelectorAll('#acct-presets .range-preset').forEach(function (b) {
        b.classList.remove('active');
    });
    acctReload();
}

function acctSub(name) {
    acctState.sub = name;
    document.querySelectorAll('#tab-accounting .fin-subtab').forEach(function (b) {
        b.classList.toggle('active', b.dataset.sub === name);
    });
    acctReload();
}

function acctQuery(extra) {
    var p = ['preset=' + encodeURIComponent(acctState.preset), 'book=' + encodeURIComponent(acctState.book)];
    if (acctState.preset === 'custom') {
        p.push('from=' + encodeURIComponent(acctState.from));
        p.push('to='   + encodeURIComponent(acctState.to));
    }
    for (var k in (extra || {})) {
        if (extra[k] !== '' && extra[k] != null) p.push(k + '=' + encodeURIComponent(extra[k]));
    }
    return p.join('&');
}

function acctFetch(report, extra) {
    var e = extra || {};
    e.report = report;
    return fetch('accounting-api.php?' + acctQuery(e)).then(function (r) { return r.json(); });
}

function acctExportUrl(report, extra) {
    var e = extra || {};
    e.report = report;
    e.format = 'csv';
    return 'accounting-api.php?' + acctQuery(e);
}

function acctReload() {
    acctState.book = document.getElementById('acct-book').value;
    var body = document.getElementById('acct-body');
    body.innerHTML = '<p class="emp-empty">Loading&hellip;</p>';

    var sub = acctState.sub;
    var renderers = {
        overview:  acctRenderOverview,
        pl:        acctRenderPl,
        trial:     acctRenderTrial,
        ledger:    acctRenderLedger,
        statement: acctRenderStatement,
        expenses:  acctRenderExpenses,
        payroll:   acctRenderPayroll,
        health:    acctRenderHealth,
        pack:      acctRenderPack,
    };
    var reportFor = {
        overview: 'overview', pl: 'pl', trial: 'trial_balance', ledger: 'ledger',
        statement: 'account_statement', expenses: 'expense_matrix',
        payroll: 'payroll_register', health: 'issues', pack: 'overview',
    };

    // The statement needs an account chosen before it can ask for anything.
    if (sub === 'statement' && !acctState.statementAccount) {
        acctLoadAccounts().then(function () { acctRenderStatement(null); });
        return;
    }

    var extra = {};
    if (sub === 'statement') extra.account_id = acctState.statementAccount;
    if (sub === 'ledger')    extra = acctLedgerFilters();

    acctFetch(reportFor[sub], extra).then(function (d) {
        if (d.error) { body.innerHTML = '<p class="rep-empty">' + escFin(d.error) + '</p>'; return; }
        if (d.range) {
            acctState.range = d.range;
            document.getElementById('acct-range-label').textContent = d.range.label
                + ' (' + d.range.from + ' → ' + d.range.to + ')';
        }
        (renderers[sub] || acctRenderOverview)(d);
    }).catch(function (err) {
        body.innerHTML = '<p class="rep-empty">Could not load: ' + escFin(err.message || err) + '</p>';
    });
}

function acctLoadAccounts() {
    if (acctState.accounts.length) return Promise.resolve(acctState.accounts);
    return fetch('accounts-api.php').then(function (r) { return r.json(); }).then(function (d) {
        acctState.accounts = d.accounts || [];
        return acctState.accounts;
    });
}

// ── Shared render helpers ────────────────────────────────────────────────

function acctMoney(v, cur) {
    return fmtMoney(v || 0, cur || 'PKR');
}

function acctSigned(v, cur) {
    var cls = v < 0 ? 'neg' : (v > 0 ? 'pos' : '');
    return '<span class="' + cls + '">' + acctMoney(v, cur) + '</span>';
}

function acctExportBar(report, extra, note) {
    return '<div class="section-head" style="margin-bottom:14px;">'
         + '<div style="font-size:12px;color:var(--text-muted);">' + (note || '') + '</div>'
         + '<a class="finance-export" href="' + acctExportUrl(report, extra) + '">&#8681; Export CSV</a></div>';
}

function acctStat(label, value, sub, cls) {
    return '<div class="stat-cell"><div class="sc-label">' + escFin(label) + '</div>'
         + '<div class="sc-value ' + (cls || '') + '">' + value + '</div>'
         + (sub ? '<div class="sc-sub">' + sub + '</div>' : '') + '</div>';
}

// ── Overview ─────────────────────────────────────────────────────────────

function acctRenderOverview(d) {
    var cur = d.currency || 'PKR';
    var t = d.totals || {};
    var b = d.balances || {};
    var h = '';

    h += '<div class="stat-strip">'
       + acctStat('Income',   acctMoney(t.income, cur))
       + acctStat('Expenses', acctMoney(t.expense, cur))
       + acctStat('Net',      acctMoney(t.net, cur), null, (t.net || 0) < 0 ? 'neg' : 'pos')
       // Plain text — acctStat escapes the label, so an entity here would show
       // up on screen as the literal "&amp;".
       + acctStat('Cash & bank', acctMoney((b.assets || {}).total, cur), 'at period end')
       + acctStat('Owed to you',  acctMoney((b.receivables || {}).total, cur), 'advances &amp; loans')
       // Liabilities carry a negative balance, so the server sends `owed` as
       // the positive amount actually due.
       + acctStat('You owe',      acctMoney((b.liabilities || {}).owed, cur), 'PF, EOBI, tax withheld')
       + '</div>';

    h += '<div class="card"><div class="card-title" style="margin-bottom:4px;">Money in and out by month</div>'
       + acctCashFlowChart(d.cash_flow || [], cur) + '</div>';

    h += '<div class="dash-cols" style="margin-top:18px;">';

    h += '<div class="card"><div class="card-title" style="margin-bottom:14px;">Where the money went</div>';
    var top = d.top_expense_categories || [];
    if (!top.length) {
        h += '<p class="emp-empty">Nothing recorded in this period.</p>';
    } else {
        var max = Math.max.apply(null, top.map(function (r) { return r.total; }));
        h += '<div class="hbar-list">' + top.map(function (r) {
            return '<div class="hbar-row"><div class="hbar-name">' + escFin(r.category) + '</div>'
                 + '<div class="hbar-track"><div class="hbar-fill" style="width:'
                 + (max > 0 ? Math.round(r.total / max * 100) : 0) + '%"></div></div>'
                 + '<div class="hbar-amt">' + acctMoney(r.total, cur) + '</div></div>';
        }).join('') + '</div>';
    }
    h += '</div>';

    h += '<div>';
    var pr = (d.payroll || {}).totals || {};
    h += '<div class="card"><div class="card-title" style="margin-bottom:12px;">Payroll this period</div>'
       + '<div style="font-size:13px;line-height:2;color:var(--text-muted);">'
       + 'Payslips issued <strong style="color:var(--text);float:right;">' + (pr.count || 0) + '</strong><br>'
       + 'Gross <strong style="color:var(--text);float:right;">' + acctMoney(pr.gross, cur) + '</strong><br>'
       + 'Paid out <strong style="color:var(--text);float:right;">' + acctMoney(pr.net, cur) + '</strong><br>'
       + 'Withheld <strong style="color:var(--text);float:right;">' + acctMoney(pr.statutory, cur) + '</strong>'
       + '</div></div>';

    var issues = d.issues || [];
    h += '<div class="card" style="margin-top:18px;"><div class="card-title" style="margin-bottom:12px;">Books health</div>';
    if (!issues.length) {
        h += '<div class="health-clear">&#10003; Nothing looks wrong in this period.</div>';
    } else {
        h += issues.map(function (i) {
            return '<div class="health-item sev-' + escFin(i.severity) + '" style="padding:10px 12px;margin-bottom:8px;">'
                 + '<div class="health-head"><span class="health-title" style="font-size:12.5px;">' + escFin(i.title) + '</span>'
                 + '<span class="health-count">' + i.count + '</span></div></div>';
        }).join('');
        h += '<button class="health-toggle" onclick="acctSub(\'health\')">See the detail &rarr;</button>';
    }
    h += '</div></div></div>';

    document.getElementById('acct-body').innerHTML = h;
}

// Grouped bars: money in beside money out, one pair per month. Net is shown in
// the tooltip rather than as a third bar — it is derived from the other two,
// not a category of its own, and a third series would need a colour that no
// longer passes the contrast checks the other two were chosen against.
function acctCashFlowChart(rows, cur) {
    if (!rows.length) return '<p class="emp-empty">No activity in this period.</p>';

    var max = 0;
    rows.forEach(function (r) { max = Math.max(max, r.income, r.expense); });
    if (max <= 0) return '<p class="emp-empty">No activity in this period.</p>';

    var h = '<div class="cf-chart">'
          + '<div class="cf-legend">'
          + '<span><i class="cf-swatch in"></i> Money in</span>'
          + '<span><i class="cf-swatch out"></i> Money out</span>'
          + '</div><div class="cf-plot">';

    h += rows.map(function (r) {
        var hi = Math.max(1, Math.round(r.income  / max * 100));
        var ho = Math.max(1, Math.round(r.expense / max * 100));
        return '<div class="cf-col">'
             + '<div class="cf-tip"><b>' + escFin(r.label) + '</b><br>'
             + '<span class="t-in">In</span> ' + acctMoney(r.income, cur) + '<br>'
             + '<span class="t-out">Out</span> ' + acctMoney(r.expense, cur) + '<br>'
             + 'Net ' + acctMoney(r.net, cur) + '</div>'
             + '<div class="cf-bar in"  style="height:' + hi + '%"></div>'
             + '<div class="cf-bar out" style="height:' + ho + '%"></div>'
             + '</div>';
    }).join('');

    h += '</div><div class="cf-x">'
       + rows.map(function (r) { return '<span>' + escFin(r.label.replace(' ', ' ')) + '</span>'; }).join('')
       + '</div></div>';
    return h;
}

// ── Profit & Loss ────────────────────────────────────────────────────────

function acctRenderPl(d) {
    var cur = d.currency || 'PKR';
    var t = d.headline || { income: 0, expense: 0, net: 0 };
    var change = (d.change || {})[cur] || {};
    var h = acctExportBar('pl', {}, 'Cash basis. Transfers between your own accounts are excluded.');

    h += '<div class="stat-strip">'
       + acctStat('Income',   acctMoney(t.income, cur),  acctDelta(change.income, cur))
       + acctStat('Expenses', acctMoney(t.expense, cur), acctDelta(change.expense, cur))
       + acctStat('Net profit', acctMoney(t.net, cur),   acctDelta(change.net, cur),
                  t.net < 0 ? 'neg' : 'pos')
       + '</div>';

    h += '<div class="card"><div class="rep-table-wrap"><table class="rep-table">'
       + '<thead><tr><th>Category</th><th>Code</th><th class="num">Entries</th><th class="num">Amount</th></tr></thead><tbody>';

    h += acctPlSection('Income', d.income || [], t.income, cur);
    h += acctPlSection('Expenses', d.expense || [], t.expense, cur);
    var prevLabel = ((d.previous || {}).range || {}).label || '';

    h += '<tr class="rep-total"><td colspan="3">Net profit for the period</td>'
       + '<td class="num">' + acctSigned(t.net, cur) + '</td></tr>';
    h += '</tbody></table></div>';

    if (prevLabel) {
        h += '<p class="rep-note">Comparisons above are against <strong>' + escFin(prevLabel)
           + '</strong>, the same length of time immediately before this period.</p>';
    }

    var others = Object.keys(d.totals || {}).filter(function (c) { return c !== cur; });
    if (others.length) {
        h += '<p class="rep-note"><strong>Note:</strong> there is also activity in '
           + others.join(', ') + '. Those are reported separately and are not added into the '
           + 'figures above &mdash; mixing currencies in one total would be meaningless.</p>';
    }
    h += '</div>';
    document.getElementById('acct-body').innerHTML = h;
}

function acctPlSection(title, rows, total, cur) {
    var h = '<tr class="rep-subhead"><td colspan="4">' + title + '</td></tr>';
    if (!rows.length) {
        return h + '<tr><td colspan="4" style="color:var(--text-faint);">Nothing recorded</td></tr>';
    }
    h += rows.filter(function (r) { return r.currency === cur; }).map(function (r) {
        return '<tr><td>' + escFin(r.category)
             + (r.tax_deductible === false ? ' <span class="status-pill status-voided">not claimable</span>' : '')
             + '</td><td class="rep-code">' + escFin(r.code || '') + '</td>'
             + '<td class="num">' + r.count + '</td>'
             + '<td class="num">' + acctMoney(r.total, cur) + '</td></tr>';
    }).join('');
    h += '<tr class="rep-total"><td colspan="3">Total ' + title.toLowerCase() + '</td>'
       + '<td class="num">' + acctMoney(total, cur) + '</td></tr>';
    return h;
}

// The server sends percent as null rather than Infinity when the previous
// period was zero, so there is nothing sensible to divide by and the figure
// itself is shown instead.
function acctDelta(ch, cur) {
    if (!ch) return '';
    if (ch.percent === null || ch.percent === undefined) {
        return ch.previous ? '' : 'nothing in the period before';
    }
    var arrow = ch.direction === 'up' ? '↑' : (ch.direction === 'down' ? '↓' : '');
    return arrow + ' ' + Math.abs(ch.percent).toFixed(1) + '% vs ' + acctMoney(ch.previous, cur);
}

// ── Trial balance ────────────────────────────────────────────────────────

function acctRenderTrial(d) {
    var h = acctExportBar('trial_balance', {},
        'Opening balance, movement and closing balance for every account.');

    var checks = d.checks || {};
    var bad = (d.warnings || []).length > 0;
    h += '<div class="health-item ' + (bad ? 'sev-error' : '') + '" style="'
       + (bad ? '' : 'background:var(--success-soft);border-left-color:var(--success);') + '">'
       + '<div class="health-head"><span class="health-title">'
       + (bad ? 'These books do not balance' : '✓ These books balance')
       + '</span></div><div class="health-detail">';
    Object.keys(checks).forEach(function (c) {
        var k = checks[c];
        h += c + ': accounts moved ' + acctMoney(k.account_movement, c)
           + ', net profit was ' + acctMoney(k.net_profit, c)
           + (k.balanced ? ' — agreed.' : ' — out by ' + acctMoney(k.difference, c) + '.') + '<br>';
    });
    if (bad) h += '<br>' + (d.warnings || []).map(escFin).join('<br>');
    h += '</div></div>';

    h += '<div class="card"><div class="card-title" style="margin-bottom:14px;">Accounts</div>'
       + '<div class="rep-table-wrap"><table class="rep-table"><thead><tr>'
       + '<th>Account</th><th>Kind</th><th class="num">Opening</th><th class="num">In</th>'
       + '<th class="num">Out</th><th class="num">Closing</th></tr></thead><tbody>';

    var accs = d.accounts || [];
    if (!accs.length) {
        h += '<tr><td colspan="6" class="rep-empty">No account activity in this period.</td></tr>';
    } else {
        h += accs.map(function (a) {
            return '<tr><td>' + escFin(a.name) + '</td>'
                 + '<td><span class="status-pill status-voided">' + escFin(a.kind) + '</span></td>'
                 + '<td class="num">' + acctMoney(a.opening, a.currency) + '</td>'
                 + '<td class="num">' + (a.debit  ? acctMoney(a.debit,  a.currency) : '—') + '</td>'
                 + '<td class="num">' + (a.credit ? acctMoney(a.credit, a.currency) : '—') + '</td>'
                 + '<td class="num"><strong>' + acctMoney(a.closing, a.currency) + '</strong></td></tr>';
        }).join('');
    }
    h += '</tbody></table></div>';
    h += '<p class="rep-note">A liability such as <strong>Statutory Payables</strong> shows as a '
       + 'negative balance &mdash; that is money you are holding for someone else, so the amount '
       + 'you owe is the figure without its minus sign.</p></div>';

    document.getElementById('acct-body').innerHTML = h;
}

// ── General ledger ───────────────────────────────────────────────────────

function acctLedgerFilters() {
    var g = function (id) { var e = document.getElementById(id); return e ? e.value : ''; };
    return {
        account_id:  g('led-account'),
        type:        g('led-type'),
        search:      g('led-search'),
        include_void: document.getElementById('led-void') && document.getElementById('led-void').checked ? 1 : '',
    };
}

function acctRenderLedger(d) {
    var f = acctLedgerFilters();
    var h = '<div class="card"><div class="finance-controls">'
          + '<select id="led-account" onchange="acctReload()"><option value="">All accounts</option>'
          + acctState.accounts.map(function (a) {
                return '<option value="' + escFin(a.id) + '"' + (a.id === f.account_id ? ' selected' : '') + '>'
                     + escFin(a.name) + '</option>';
            }).join('')
          + '</select>'
          + '<select id="led-type" onchange="acctReload()">'
          + ['', 'income', 'expense', 'transfer_in', 'transfer_out'].map(function (t) {
                var lbl = t === '' ? 'All types' : t.replace('_', ' ');
                return '<option value="' + t + '"' + (t === f.type ? ' selected' : '') + '>' + lbl + '</option>';
            }).join('')
          + '</select>'
          + '<input type="text" id="led-search" placeholder="Search notes or counterparty" value="'
          + escFin(f.search) + '" onchange="acctReload()" style="padding:8px 12px;border:1px solid var(--border);border-radius:6px;font:inherit;">'
          + '<label style="font-size:12.5px;color:var(--text-muted);display:flex;align-items:center;gap:6px;">'
          + '<input type="checkbox" id="led-void" onchange="acctReload()"' + (f.include_void ? ' checked' : '') + '> show voided</label>'
          + '<a class="finance-export" href="' + acctExportUrl('ledger', f) + '">&#8681; Export CSV</a>'
          + '</div>';

    var rows = d.entries || [];
    var t = d.totals || {};
    h += '<div style="font-size:12.5px;color:var(--text-muted);margin-bottom:12px;">'
       + rows.length + ' entries — in ' + acctMoney(t.income) + ', out ' + acctMoney(t.expense)
       + ', net ' + acctMoney(t.net) + '</div>';

    h += '<div class="rep-table-wrap"><table class="rep-table"><thead><tr>'
       + '<th>Date</th><th>Account</th><th>Category</th><th>Counterparty</th>'
       + '<th>Description</th><th class="num">Amount</th></tr></thead><tbody>';

    if (!rows.length) {
        h += '<tr><td colspan="6" class="rep-empty">Nothing matches those filters.</td></tr>';
    } else {
        h += rows.map(function (e) {
            return '<tr' + (e.void ? ' style="opacity:.45;text-decoration:line-through;"' : '') + '>'
                 + '<td style="white-space:nowrap;color:var(--text-faint);">' + escFin(e.date) + '</td>'
                 + '<td>' + escFin(e.account_name) + '</td>'
                 + '<td>' + escFin(e.category_name || '—') + '</td>'
                 + '<td>' + escFin(e.counterparty || '—') + '</td>'
                 + '<td>' + escFin(e.description || '') + (e.source === 'payroll'
                     ? ' <span class="status-pill status-posted">payroll</span>' : '')
                   + (e.source === 'recurring' ? ' <span class="status-pill status-pending">recurring</span>' : '')
                 + '</td>'
                 + '<td class="num">' + acctSigned(e.signed, e.currency) + '</td></tr>';
        }).join('');
    }
    h += '</tbody></table></div></div>';
    document.getElementById('acct-body').innerHTML = h;
}

// ── Account statement ────────────────────────────────────────────────────

function acctRenderStatement(d) {
    var h = '<div class="card"><div class="finance-controls">'
          + '<select id="stmt-account" onchange="acctPickStatement(this.value)">'
          + '<option value="">— Choose an account —</option>'
          + acctState.accounts.map(function (a) {
                return '<option value="' + escFin(a.id) + '"'
                     + (a.id === acctState.statementAccount ? ' selected' : '') + '>'
                     + escFin(a.name) + '</option>';
            }).join('')
          + '</select>';
    if (acctState.statementAccount) {
        h += '<a class="finance-export" href="'
           + acctExportUrl('account_statement', { account_id: acctState.statementAccount })
           + '">&#8681; Export CSV</a>';
    }
    h += '</div>';

    if (!d || !d.account) {
        h += '<p class="emp-empty">Pick an account to see its statement &mdash; opening balance, '
           + 'every movement, and a running balance you can tie against a bank statement.</p></div>';
        document.getElementById('acct-body').innerHTML = h;
        return;
    }

    var cur = d.account.currency;
    h += '<div class="stat-strip">'
       + acctStat('Opening balance', acctMoney(d.opening, cur), escFin(d.from))
       + acctStat('Money in',  acctMoney((d.totals || {}).income + (d.totals || {}).transfer_in, cur))
       + acctStat('Money out', acctMoney((d.totals || {}).expense + (d.totals || {}).transfer_out, cur))
       + acctStat('Closing balance', acctMoney(d.closing, cur), escFin(d.to))
       + '</div>';

    h += '<div class="rep-table-wrap"><table class="rep-table"><thead><tr>'
       + '<th>Date</th><th>Description</th><th>Category</th>'
       + '<th class="num">In</th><th class="num">Out</th><th class="num">Balance</th></tr></thead><tbody>'
       + '<tr><td colspan="5" style="color:var(--text-faint);">Opening balance</td>'
       + '<td class="num"><strong>' + acctMoney(d.opening, cur) + '</strong></td></tr>';

    (d.entries || []).forEach(function (e) {
        var inAmt  = e.signed > 0 ? e.amount : 0;
        var outAmt = e.signed < 0 ? e.amount : 0;
        h += '<tr><td style="white-space:nowrap;color:var(--text-faint);">' + escFin(e.date) + '</td>'
           + '<td>' + escFin(e.description || e.counterparty || '—') + '</td>'
           + '<td>' + escFin(e.category_name || '—') + '</td>'
           + '<td class="num">' + (inAmt  ? acctMoney(inAmt, cur)  : '') + '</td>'
           + '<td class="num">' + (outAmt ? acctMoney(outAmt, cur) : '') + '</td>'
           + '<td class="num">' + acctMoney(e.running_balance, cur) + '</td></tr>';
    });

    h += '<tr class="rep-total"><td colspan="5">Closing balance</td>'
       + '<td class="num">' + acctMoney(d.closing, cur) + '</td></tr>';
    h += '</tbody></table></div></div>';
    document.getElementById('acct-body').innerHTML = h;
}

function acctPickStatement(id) {
    acctState.statementAccount = id;
    if (!id) { acctRenderStatement(null); return; }
    acctReload();
}

// ── Expenses by month ────────────────────────────────────────────────────

function acctRenderExpenses(d) {
    var months = d.months || [];
    var h = acctExportBar('expense_matrix', {},
        'Every expense category against every month, so a cost that jumped is easy to spot.');

    h += '<div class="card"><div class="matrix-wrap"><table class="matrix-table"><thead><tr>'
       + '<th class="mx-head-name">Category</th>'
       + months.map(function (m) {
             return '<th>' + escFin(m.slice(5) + '/' + m.slice(2, 4)) + '</th>';
         }).join('')
       + '<th>Total</th></tr></thead><tbody>';

    var rows = d.rows || [];
    if (!rows.length) {
        h += '<tr><td class="mx-name" colspan="' + (months.length + 2) + '">No expenses in this period.</td></tr>';
    } else {
        h += rows.map(function (r) {
            return '<tr><td class="mx-name">' + escFin(r.category) + '</td>'
                 + months.map(function (m) {
                       var v = r.by_month[m] || 0;
                       return '<td' + (v ? '' : ' class="mx-zero"') + '>' + (v ? acctMoney(v) : '—') + '</td>';
                   }).join('')
                 + '<td><strong>' + acctMoney(r.total) + '</strong></td></tr>';
        }).join('');
        h += '<tr class="rep-total"><td class="mx-name">Total</td>'
           + months.map(function (m) { return '<td>' + acctMoney((d.month_totals || {})[m] || 0) + '</td>'; }).join('')
           + '<td>' + acctMoney(d.grand_total) + '</td></tr>';
    }
    h += '</tbody></table></div></div>';
    document.getElementById('acct-body').innerHTML = h;
}

// ── Payroll register ─────────────────────────────────────────────────────

function acctRenderPayroll(d) {
    var reg = d.register || d;
    var rec = d.reconciliation || {};
    var t = reg.totals || {};
    var h = acctExportBar('payroll_register', {},
        'Every payslip issued in this period, and whether the ledger agrees.');

    h += '<div class="stat-strip">'
       + acctStat('Payslips', (t.count || 0))
       + acctStat('Gross',    acctMoney(t.gross))
       + acctStat('Withheld', acctMoney(t.statutory), 'PF, EOBI, tax')
       + acctStat('Advances recovered', acctMoney(t.loan))
       + acctStat('Net paid', acctMoney(t.net))
       + '</div>';

    if (rec && rec.matches === false) {
        h += '<div class="health-item sev-error"><div class="health-head">'
           + '<span class="health-title">Payslips and the ledger disagree</span></div>'
           + '<div class="health-detail">The payslips add up to ' + acctMoney(rec.expected_from_payslips)
           + ' of salary cost, but the ledger holds ' + acctMoney(rec.in_ledger)
           + ' &mdash; a difference of ' + acctMoney(rec.difference) + '.<br><br>'
           + (rec.unposted_count
                ? '<strong>' + rec.unposted_count + ' payslip(s) were never posted.</strong> '
                + 'Post them from the Payroll screen.'
                : 'Every payslip here is marked posted, so this is almost certainly payroll issued '
                + 'before the ledger booked the full cost of employing someone: only net pay reached '
                + 'the books, while the provident fund, EOBI, tax and any advance recovered did not. '
                + 'Re-generating those months from the Payroll screen reverses the old entry and '
                + 'books all of it. Failing that, check whether a salary was also typed in by hand.')
           + '</div></div>';
    } else if (rec && rec.matches === true) {
        h += '<div class="health-item" style="background:var(--success-soft);border-left-color:var(--success);">'
           + '<div class="health-head"><span class="health-title">'
           + '✓ Payslips and the ledger agree</span></div></div>';
    }

    h += '<div class="card"><div class="card-title" style="margin-bottom:14px;">By month</div>'
       + '<div class="rep-table-wrap"><table class="rep-table"><thead><tr>'
       + '<th>Month</th><th class="num">Slips</th><th class="num">Gross</th><th class="num">Withheld</th>'
       + '<th class="num">Advance</th><th class="num">Penalty</th><th class="num">Net paid</th>'
       + '</tr></thead><tbody>';
    var months = reg.by_month || [];
    if (!months.length) {
        h += '<tr><td colspan="7" class="rep-empty">No payslips issued in this period.</td></tr>';
    } else {
        h += months.map(function (m) {
            return '<tr><td>' + escFin(m.label) + '</td><td class="num">' + m.count + '</td>'
                 + '<td class="num">' + acctMoney(m.gross) + '</td>'
                 + '<td class="num">' + acctMoney(m.statutory) + '</td>'
                 + '<td class="num">' + acctMoney(m.loan) + '</td>'
                 + '<td class="num">' + acctMoney(m.penalty) + '</td>'
                 + '<td class="num"><strong>' + acctMoney(m.net) + '</strong></td></tr>';
        }).join('');
    }
    h += '</tbody></table></div></div>';

    h += '<div class="card"><div class="card-title" style="margin-bottom:14px;">By person</div>'
       + '<div class="rep-table-wrap"><table class="rep-table"><thead><tr>'
       + '<th>Employee</th><th class="num">Slips</th><th class="num">Gross</th>'
       + '<th class="num">Withheld</th><th class="num">Net paid</th></tr></thead><tbody>';
    h += (reg.by_employee || []).map(function (p) {
        return '<tr><td>' + escFin(p.employee) + '</td><td class="num">' + p.count + '</td>'
             + '<td class="num">' + acctMoney(p.gross) + '</td>'
             + '<td class="num">' + acctMoney(p.statutory) + '</td>'
             + '<td class="num"><strong>' + acctMoney(p.net) + '</strong></td></tr>';
    }).join('');
    h += '</tbody></table></div></div>';

    document.getElementById('acct-body').innerHTML = h;
}

// ── Health ───────────────────────────────────────────────────────────────

function acctRenderHealth(d) {
    var issues = d.issues || [];
    var h = '<div class="card"><div class="card-title" style="margin-bottom:6px;">Books health</div>'
          + '<div class="card-subtitle">Everything a consultant would query before signing off. '
          + 'Fix these before exporting the tax pack.</div>';

    if (!issues.length) {
        h += '<div class="health-clear">&#10003; Nothing to fix. Every transfer is paired, '
           + 'everything is categorised, and no account is showing an impossible balance.</div>';
    } else {
        h += issues.map(function (i, idx) {
            var rows = i.rows || [];
            var s = '<div class="health-item sev-' + escFin(i.severity) + '">'
                  + '<div class="health-head"><span class="health-title">' + escFin(i.title) + '</span>'
                  + '<span class="health-count">' + i.count + '</span></div>'
                  + '<div class="health-detail">' + escFin(i.detail) + '</div>';
            if (rows.length) {
                s += '<button class="health-toggle" onclick="acctToggleRows(' + idx + ')">'
                   + 'Show the ' + Math.min(rows.length, 50) + ' entries &darr;</button>'
                   + '<div class="health-rows" id="health-rows-' + idx + '" style="display:none;">'
                   + '<div class="rep-table-wrap"><table class="rep-table"><tbody>'
                   + rows.slice(0, 50).map(function (r) {
                         return '<tr><td style="white-space:nowrap;">' + escFin(r.date || r.name || '') + '</td>'
                              + '<td>' + escFin(r.account_name || r.counterparty || r.description || '') + '</td>'
                              + '<td class="num">' + acctMoney(r.amount != null ? r.amount : r.balance) + '</td></tr>';
                     }).join('')
                   + '</tbody></table></div></div>';
            }
            return s + '</div>';
        }).join('');
    }
    h += '</div>';
    document.getElementById('acct-body').innerHTML = h;
}

function acctToggleRows(idx) {
    var el = document.getElementById('health-rows-' + idx);
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

// ── Tax pack ─────────────────────────────────────────────────────────────

function acctRenderPack() {
    var packs = [
        ['tax_pack', 'Complete tax pack',
         'Everything below in one file: profit &amp; loss, trial balance, payroll register, '
         + 'closing balances and any outstanding issues. This is the one to send.'],
        ['pl', 'Profit &amp; loss', 'Income and expenses by category for the period.'],
        ['trial_balance', 'Trial balance', 'Opening, movement and closing for every account.'],
        ['ledger', 'General ledger', 'Every single transaction, in date order.'],
        ['payroll_register', 'Payroll register', 'Every payslip with its full breakdown.'],
        ['expense_matrix', 'Expenses by month', 'Category against month.'],
        ['cash_flow', 'Cash flow', 'Money in, money out and net, month by month.'],
        ['counterparties', 'Who paid you, who you paid', 'Totals per client and per supplier.'],
        ['advances', 'Advances &amp; loans', 'What each person still owes.'],
    ];

    var h = '<div class="card"><div class="card-title" style="margin-bottom:6px;">Send to your accountant</div>'
          + '<div class="card-subtitle">Each file names the company, the period and the book in its '
          + 'header, so they stay meaningful once they are sitting in someone else\'s inbox. '
          + 'Period: <strong>' + escFin(acctState.range ? acctState.range.label : '') + '</strong>.</div>'
          + '<div class="pack-grid">';

    h += packs.map(function (p) {
        return '<div class="pack-card"><h4>' + p[1] + '</h4><p>' + p[2] + '</p>'
             + '<a class="finance-export" style="align-self:flex-start;" href="'
             + acctExportUrl(p[0], {}) + '">&#8681; Download CSV</a></div>';
    }).join('');

    h += '</div></div>';
    document.getElementById('acct-body').innerHTML = h;
}

// ── Recurring rules ──────────────────────────────────────────────────────

var recEditingId = null;

function recAlert(msg, ok) {
    var el = document.getElementById('rec-alert');
    el.className = 'team-alert ' + (ok ? 'success' : 'error');
    el.textContent = msg;
    el.style.display = 'block';
    if (ok) setTimeout(function () { el.style.display = 'none'; }, 4000);
}

function loadRecurring() {
    var body = document.getElementById('rec-body');
    body.innerHTML = '<p class="emp-empty">Loading&hellip;</p>';
    Promise.all([
        fetch('recurring-api.php').then(function (r) { return r.json(); }),
        acctLoadAccounts(),
        fetch('categories-api.php').then(function (r) { return r.json(); }).catch(function () { return { categories: [] }; }),
    ]).then(function (res) {
        var d = res[0];
        if (d.error) { body.innerHTML = '<p class="rep-empty">' + escFin(d.error) + '</p>'; return; }
        recCategories = (res[2] && res[2].categories) || [];
        recRenderList(d);
    });
}

var recCategories = [];

function recRenderList(d) {
    var rules = d.rules || [];
    var due = d.due_count || 0;
    var btn = document.getElementById('rec-run-all');
    if (btn) {
        btn.disabled = due === 0;
        btn.textContent = due ? ('Post ' + due + ' due') : 'Nothing due';
    }

    if (!rules.length) {
        document.getElementById('rec-body').innerHTML =
            '<p class="emp-empty">No recurring rules yet. Add rent, internet or any other fixed '
          + 'monthly amount and it will be booked for you instead of remembered.</p>';
        return;
    }

    var h = '<div class="rep-table-wrap"><table class="rep-table"><thead><tr>'
          + '<th>Rule</th><th>Account</th><th>Category</th><th class="num">Amount</th>'
          + '<th>Day</th><th>Next due</th><th></th></tr></thead><tbody>';

    h += rules.map(function (r) {
        var missed = (r.missed_periods || []).length;
        return '<tr id="rec-row-' + escFin(r.id) + '"' + (r.active ? '' : ' style="opacity:.5;"') + '>'
             + '<td><strong>' + escFin(r.name) + '</strong>'
             + '<span class="fin-meta">' + escFin(r.counterparty || r.description || '') + '</span></td>'
             + '<td>' + escFin(r.account_name || '') + '</td>'
             + '<td>' + escFin(r.category_name || '—') + '</td>'
             + '<td class="num"><span class="' + (r.type === 'income' ? 'pos' : '') + '">'
             + acctMoney(r.amount, r.currency) + '</span></td>'
             + '<td>' + escFin(String(r.day_of_month)) + '</td>'
             + '<td>' + (r.is_due
                   ? '<span class="status-pill status-pending">' + (missed > 1 ? missed + ' months due' : 'due now') + '</span>'
                   : escFin(r.next_due_period || '—')) + '</td>'
             + '<td class="fin-actions">'
             + (r.is_due ? '<button class="btn-edit" onclick="recRun(\'' + escFin(r.id) + '\')">Post now</button> ' : '')
             + '<button class="btn-edit" onclick="recEdit(\'' + escFin(r.id) + '\')">Edit</button> '
             + '<button class="btn-edit" onclick="recToggle(\'' + escFin(r.id) + '\',' + (r.active ? 0 : 1) + ')">'
             + (r.active ? 'Pause' : 'Resume') + '</button> '
             + '<button class="btn-delete" onclick="recDelete(\'' + escFin(r.id) + '\')">Delete</button>'
             + '</td></tr>';
    }).join('');

    h += '</tbody></table></div>';
    document.getElementById('rec-body').innerHTML = h;
    recRules = rules;
}

var recRules = [];

function recOpenForm() {
    recEditingId = null;
    document.getElementById('rec-form-title').textContent = 'New recurring rule';
    document.getElementById('rec-form').reset();
    document.getElementById('rec-start').value = new Date().toISOString().slice(0, 7);
    recFillSelects();
    openCardModal('rec-form-card');
}

function recCloseForm() {
    closeCardModal();
    document.getElementById('rec-form-card').style.display = 'none';
    recEditingId = null;
}

function recFillSelects(rule) {
    var acc = document.getElementById('rec-account');
    acc.innerHTML = acctState.accounts.map(function (a) {
        return '<option value="' + escFin(a.id) + '">' + escFin(a.name) + '</option>';
    }).join('');
    recTypeChange();
    if (rule) {
        acc.value = rule.account_id;
        document.getElementById('rec-category').value = rule.category_id || '';
    }
}

function recTypeChange() {
    var type = document.getElementById('rec-type').value;
    var sel = document.getElementById('rec-category');
    var keep = sel.value;
    sel.innerHTML = '<option value="">— none —</option>'
        + recCategories.filter(function (c) { return c.type === type && !c.archived; })
            .map(function (c) { return '<option value="' + escFin(c.id) + '">' + escFin(c.name) + '</option>'; })
            .join('');
    sel.value = keep;
}

function recEdit(id) {
    var r = recRules.filter(function (x) { return x.id === id; })[0];
    if (!r) return;
    recEditingId = id;
    document.getElementById('rec-form-title').textContent = 'Edit rule';
    document.getElementById('rec-name').value = r.name;
    document.getElementById('rec-type').value = r.type;
    document.getElementById('rec-amount').value = r.amount;
    document.getElementById('rec-day').value = r.day_of_month;
    document.getElementById('rec-start').value = r.start_period;
    document.getElementById('rec-end').value = r.end_period || '';
    document.getElementById('rec-counterparty').value = r.counterparty || '';
    document.getElementById('rec-description').value = r.description || '';
    recFillSelects(r);
    openCardModal('rec-form-card', document.getElementById('rec-row-' + id));
}

function recSubmit(ev) {
    ev.preventDefault();
    var payload = {
        action:       recEditingId ? 'update' : 'create',
        id:           recEditingId,
        name:         document.getElementById('rec-name').value,
        type:         document.getElementById('rec-type').value,
        amount:       parseFloat(document.getElementById('rec-amount').value),
        account_id:   document.getElementById('rec-account').value,
        category_id:  document.getElementById('rec-category').value,
        day_of_month: parseInt(document.getElementById('rec-day').value, 10),
        start_period: document.getElementById('rec-start').value,
        end_period:   document.getElementById('rec-end').value,
        counterparty: document.getElementById('rec-counterparty').value,
        description:  document.getElementById('rec-description').value,
    };
    apiPost('recurring-api.php', payload).then(function (d) {
        if (d.error) return recAlert(d.error, false);
        recAlert('Rule saved.', true);
        recCloseForm();
        loadRecurring();
    });
}

function recRun(id) {
    apiPost('recurring-api.php', { action: 'run', id: id }).then(function (d) {
        if (d.error) return recAlert(d.error, false);
        recAlert(recRunMessage(d), true);
        loadRecurring();
    });
}

function recRunAll() {
    apiPost('recurring-api.php', { action: 'run', all: true }).then(function (d) {
        if (d.error) return recAlert(d.error, false);
        recAlert(recRunMessage(d), true);
        loadRecurring();
    });
}

// A run can book several months for one rule at once, so the useful number is
// how many entries were written rather than how many rules ran.
function recRunMessage(d) {
    var n = d.created_count || 0;
    if (!n) {
        var why = (d.results || []).filter(function (r) { return r.skipped && r.reason; });
        return why.length ? why[0].reason : 'Nothing was due.';
    }
    var amounts = Object.keys(d.total_by_currency || {}).map(function (c) {
        return fmtMoney(d.total_by_currency[c], c);
    }).join(', ');
    return 'Booked ' + n + ' entr' + (n === 1 ? 'y' : 'ies') + (amounts ? ' — ' + amounts : '') + '.';
}

function recToggle(id, active) {
    apiPost('recurring-api.php', { action: 'toggle', id: id, active: active }).then(function (d) {
        if (d.error) return recAlert(d.error, false);
        loadRecurring();
    });
}

function recDelete(id) {
    var r = recRules.filter(function (x) { return x.id === id; })[0];
    if (!confirm('Delete the rule "' + (r ? r.name : id) + '"?\n\nEntries it already booked stay in the ledger.')) return;
    apiPost('recurring-api.php', { action: 'delete', id: id }).then(function (d) {
        if (d.error) return recAlert(d.error, false);
        recAlert('Rule deleted.', true);
        loadRecurring();
    });
}
</script>

</body>
</html>

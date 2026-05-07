<?php
require_once __DIR__ . '/auth.php';
requireLogin();

$today         = date('l, F j, Y');
$default_date  = date('Y-m-d');
$default_month = date('Y-m');

// Load saved employees for initial page render
$empFile   = __DIR__ . '/employees.json';
$employees = file_exists($empFile) ? (json_decode(file_get_contents($empFile), true) ?: []) : [];
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
        <div class="nav-label">Documents</div>

        <a class="nav-item active" id="nav-offer" href="#"
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

        <a class="nav-item" id="nav-finances" href="#"
           onclick="showTab('finances', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <line x1="12" y1="1" x2="12" y2="23"/>
                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/>
            </svg>
            Finances
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
        <h1 id="page-title">Generate Offer Letter</h1>
        <div style="display: flex; align-items: center; gap: 16px;">
            <span class="topbar-user">Signed in as <strong><?= htmlspecialchars($_SESSION['admin_user']) ?></strong></span>
            <span class="topbar-date"><?= $today ?></span>
        </div>
    </div>

    <div class="content-area">

        <!-- ─────────────────────────────────────
             OFFER LETTER FORM
        ───────────────────────────────────── -->
        <div id="tab-offer" class="tab-content active">
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
                            <label>Position / Designation *</label>
                            <select name="position" id="offer-position" required>
                                <option value="">— Select Designation —</option>
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
                            <label>Designation *</label>
                            <select name="designation" id="payslip-designation" required>
                                <option value="">— Select Designation —</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Pay Period *</label>
                            <input type="month" name="pay_period" id="payslip-period" value="<?= $default_month ?>" required>
                        </div>
                    </div>

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
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-title" id="emp-form-title">Add Team Member</div>
                <div class="card-subtitle">
                    Saved employees appear instantly in dropdowns across the dashboard.
                    Salary fields auto-fill on payslip and offer letter generation.
                </div>

                <div id="team-alert" class="team-alert"></div>

                <form id="emp-form" onsubmit="saveEmployee(event)">
                    <input type="hidden" id="emp-original-name" value="">

                    <div class="section-label">Basic Information</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" id="emp-name"
                                   placeholder="e.g. Zunhara Jamil" autocomplete="off" required>
                        </div>
                        <div class="form-group">
                            <label>Designation *</label>
                            <input type="text" id="emp-designation"
                                   list="desig-suggestions"
                                   placeholder="e.g. Reverse Recruiting Agent" autocomplete="off" required>
                            <datalist id="desig-suggestions"></datalist>
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

            <!-- Employee List -->
            <div class="card">
                <div class="card-title">Team Members</div>
                <div class="card-subtitle">
                    Click <strong>Edit</strong> to update salary, allowance or deduction defaults.
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

    </div><!-- /content-area -->
</div><!-- /main -->

<script>
// ── Globals ───────────────────────────────────────────────────────────────
var teamMembers = <?= json_encode(array_values($employees)) ?>;
var CSRF        = document.querySelector('meta[name="csrf-token"]').content;
var INTERVIEW_RATE = <?= INTERVIEW_RATE ?>;

// ── Centralized fetch with CSRF ───────────────────────────────────────────
function apiPost(url, payload) {
    return fetch(url, {
        method:  'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body:    JSON.stringify(payload),
    }).then(function (r) { return r.json(); });
}

// ── On page load ──────────────────────────────────────────────────────────
(function init() {
    rebuildDropdowns();
    renderEmployeeList();
    onActivityTypeChange();
})();

// ── Rebuild all dropdowns from teamMembers ────────────────────────────────
function rebuildDropdowns() {
    var uniqueDesig = [];
    teamMembers.forEach(function(m) {
        if (uniqueDesig.indexOf(m.designation) === -1) uniqueDesig.push(m.designation);
    });
    uniqueDesig.sort();

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

    // Designation selects
    ['offer-position', 'payslip-designation'].forEach(function(id) {
        var sel = document.getElementById(id);
        if (!sel) return;
        var cur = sel.value;
        sel.innerHTML = '<option value="">— Select Designation —</option>';
        uniqueDesig.forEach(function(d) { sel.add(new Option(d, d)); });
        if (cur) sel.value = cur;
    });

    // Datalist in Manage Team
    var dl = document.getElementById('desig-suggestions');
    if (dl) {
        dl.innerHTML = '';
        uniqueDesig.forEach(function(d) {
            var opt = document.createElement('option');
            opt.value = d;
            dl.appendChild(opt);
        });
    }
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
    }
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

    var html = '<table class="emp-table">'
             + '<thead><tr><th>Name</th><th>Designation</th><th>Basic</th><th>Allowance</th>'
             + '<th>Punctuality</th><th>Deductions</th><th></th></tr></thead><tbody>';

    teamMembers.forEach(function(m) {
        var deductions = (parseInt(m.provident_fund || 0, 10))
                       + (parseInt(m.eobi             || 0, 10))
                       + (parseInt(m.professional_tax || 0, 10));
        html += '<tr>'
              + '<td>' + esc(m.name) + '</td>'
              + '<td>' + esc(m.designation) + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(m.basic_salary || 0)) + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(m.allowance    || 0)) + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(m.punctuality_bonus || 0)) + '</td>'
              + '<td style="text-align:right">' + esc(numFmt(deductions)) + '</td>'
              + '<td style="white-space:nowrap">'
              + '<button class="btn-edit" data-name="' + esc(m.name) + '" onclick="startEdit(this.dataset.name)">Edit</button>'
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
    document.getElementById('emp-basic').value            = m.basic_salary       || 0;
    document.getElementById('emp-allowance').value        = m.allowance          || 0;
    document.getElementById('emp-punctuality').value      = m.punctuality_bonus  || 0;
    document.getElementById('emp-pf').value               = m.provident_fund     || 0;
    document.getElementById('emp-eobi').value             = m.eobi               || 0;
    document.getElementById('emp-pt').value               = m.professional_tax   || 0;
    document.getElementById('emp-submit-btn').lastChild.textContent = ' Save Changes';
    document.getElementById('emp-cancel-btn').style.display = 'inline-flex';
    document.querySelector('#tab-team .card').scrollIntoView({ behavior: 'smooth' });
}

function cancelEmpEdit() {
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
        basic_salary:      parseInt(document.getElementById('emp-basic').value       || 0, 10),
        allowance:         parseInt(document.getElementById('emp-allowance').value   || 0, 10),
        punctuality_bonus: parseInt(document.getElementById('emp-punctuality').value || 0, 10),
        provident_fund:    parseInt(document.getElementById('emp-pf').value          || 0, 10),
        eobi:              parseInt(document.getElementById('emp-eobi').value        || 0, 10),
        professional_tax:  parseInt(document.getElementById('emp-pt').value          || 0, 10),
    };

    if (!payload.name || !payload.designation) {
        showAlert('error', 'Name and designation are required.');
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
    document.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.getElementById('tab-' + tab).classList.add('active');
    el.classList.add('active');
    var titles = {
        offer:        'Generate Offer Letter',
        payslip:      'Generate Payslip',
        history:      'Document History',
        activity:     'Activity Log',
        team:         'Manage Team',
        finances:     'Finances',
        'fin-setup':  'Finance Setup',
    };
    document.getElementById('page-title').textContent = titles[tab] || '';
    if (tab === 'history')     loadHistoryTab();
    if (tab === 'activity')    loadActivityList();
    if (tab === 'finances')    loadFinancesTab();
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
    document.getElementById('tx-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeTxForm() {
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
    document.getElementById('transfer-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeTransferForm() {
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
    document.getElementById('split-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeSplitForm() {
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

// ── Finance Setup tab ─────────────────────────────────────────────────────
function loadFinanceSetupTab() {
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
</script>

</body>
</html>

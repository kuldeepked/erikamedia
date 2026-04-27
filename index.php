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
        offer:    'Generate Offer Letter',
        payslip:  'Generate Payslip',
        history:  'Document History',
        activity: 'Activity Log',
        team:     'Manage Team',
    };
    document.getElementById('page-title').textContent = titles[tab] || '';
    if (tab === 'history')  loadHistoryTab();
    if (tab === 'activity') loadActivityList();
}
</script>

</body>
</html>

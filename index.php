<?php
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

        <div class="nav-label" style="margin-top: 12px;">Team</div>

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
        <span class="topbar-date"><?= $today ?></span>
    </div>

    <div class="content-area">

        <!-- ─────────────────────────────────────
             OFFER LETTER FORM
        ───────────────────────────────────── -->
        <div id="tab-offer" class="tab-content active">
            <div class="card">
                <div class="card-title">Offer Letter Generator</div>
                <div class="card-subtitle">
                    Select an employee below. If the name is not in the list, go to
                    <strong>Manage Team</strong> to add them first.
                    Then click <strong>Generate</strong> and use <strong>Print &rarr; Save as PDF</strong>.
                </div>

                <form action="generate-offer.php" method="POST" target="_blank">

                    <div class="section-label">Employee Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <select name="employee_name" id="offer-name"
                                    onchange="syncDesignation('offer')" required>
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
                            <input type="number" name="basic_salary" min="0" placeholder="e.g. 45000" required>
                        </div>
                        <div class="form-group">
                            <label>Allowance (Rs.)</label>
                            <input type="number" name="travel_allowance" min="0" value="5000">
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
                    Fill in earnings and deductions. Leave deduction fields at
                    <strong>0</strong> if not applicable &mdash; they won&rsquo;t appear on the slip.
                </div>

                <form action="generate-payslip.php" method="POST" target="_blank">

                    <div class="section-label">Employee Information</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <select name="employee_name" id="payslip-name"
                                    onchange="syncDesignation('payslip')" required>
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
                            <input type="month" name="pay_period" value="<?= $default_month ?>" required>
                        </div>
                    </div>

                    <div class="section-label">Earnings</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Basic Salary (Rs.) *</label>
                            <input type="number" name="basic_salary" min="0" placeholder="e.g. 45000" required>
                        </div>
                        <div class="form-group">
                            <label>Allowance (Rs.)</label>
                            <input type="number" name="allowance" min="0" value="5000">
                        </div>
                        <div class="form-group">
                            <label>Commission (Rs.)</label>
                            <input type="number" name="commission" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Performer Bonus (Rs.)</label>
                            <input type="number" name="performer_bonus" min="0" value="0">
                        </div>
                    </div>

                    <div class="section-label">Deductions</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Provident Fund (Rs.)</label>
                            <input type="number" name="provident_fund" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>EOBI (Rs.)</label>
                            <input type="number" name="eobi" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Loan (Rs.)</label>
                            <input type="number" name="loan" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Professional Tax (Rs.)</label>
                            <input type="number" name="professional_tax" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Absent / Late Deduction (Rs.)</label>
                            <input type="number" name="absent_late" min="0" value="0">
                        </div>
                        <div class="form-group">
                            <label>Penalty (Rs.)</label>
                            <input type="number" name="penalty" min="0" value="0">
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
             MANAGE TEAM
        ───────────────────────────────────── -->
        <div id="tab-team" class="tab-content">

            <!-- Add Employee -->
            <div class="card" style="margin-bottom: 24px;">
                <div class="card-title">Add Team Member</div>
                <div class="card-subtitle">
                    Saved employees appear instantly in the dropdowns on the Offer Letter and Payslip forms.
                </div>

                <div id="team-alert" class="team-alert"></div>

                <form id="add-emp-form" onsubmit="addEmployee(event)">
                    <div class="section-label">Employee Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" id="add-name"
                                   placeholder="e.g. Zunhara Jamil" autocomplete="off" required>
                        </div>
                        <div class="form-group">
                            <label>Designation *</label>
                            <input type="text" id="add-designation"
                                   list="desig-suggestions"
                                   placeholder="e.g. Reverse Recruiting Agent" autocomplete="off" required>
                            <datalist id="desig-suggestions"></datalist>
                        </div>
                    </div>
                    <button type="submit" class="btn-generate">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <line x1="12" y1="5" x2="12" y2="19"/>
                            <line x1="5" y1="12" x2="19" y2="12"/>
                        </svg>
                        Add to Team
                    </button>
                </form>
            </div>

            <!-- Employee List -->
            <div class="card">
                <div class="card-title">Team Members</div>
                <div class="card-subtitle">
                    All saved employees. Removing a member only removes them from this list &mdash;
                    it does not delete any generated documents.
                </div>
                <div id="employee-list"></div>
            </div>

        </div>

    </div><!-- /content-area -->
</div><!-- /main -->

<script>
// ── Employees loaded from PHP (server-side) ────────────────────────────────
var teamMembers = <?= json_encode(array_values($employees)) ?>;

// ── On page load: build all dropdowns + employee list ─────────────────────
(function init() {
    rebuildDropdowns();
    renderEmployeeList();
})();

// ── Rebuild all dropdowns from teamMembers ────────────────────────────────
function rebuildDropdowns() {
    var uniqueDesig = [];
    teamMembers.forEach(function(m) {
        if (uniqueDesig.indexOf(m.designation) === -1) uniqueDesig.push(m.designation);
    });
    uniqueDesig.sort();

    // Name selects
    ['offer-name', 'payslip-name'].forEach(function(id) {
        var sel = document.getElementById(id);
        var cur = sel.value;
        sel.innerHTML = '<option value="">— Select Employee —</option>';
        teamMembers.forEach(function(m) { sel.add(new Option(m.name, m.name)); });
        if (cur) sel.value = cur;
    });

    // Designation selects
    ['offer-position', 'payslip-designation'].forEach(function(id) {
        var sel = document.getElementById(id);
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

// ── Auto-fill designation when name is selected ───────────────────────────
function syncDesignation(form) {
    var nameEl = document.getElementById(form + '-name');
    var desEl  = form === 'offer' ? document.getElementById('offer-position')
                                  : document.getElementById('payslip-designation');
    var member = teamMembers.find(function(m) { return m.name === nameEl.value; });
    if (member) desEl.value = member.designation;
}

// ── Render employee list table ────────────────────────────────────────────
function renderEmployeeList() {
    var container = document.getElementById('employee-list');
    if (!container) return;

    if (teamMembers.length === 0) {
        container.innerHTML = '<p class="emp-empty">No team members yet. Add one above.</p>';
        return;
    }

    var html = '<table class="emp-table">'
             + '<thead><tr><th>Name</th><th>Designation</th><th></th></tr></thead>'
             + '<tbody>';

    teamMembers.forEach(function(m) {
        html += '<tr>'
              + '<td>' + esc(m.name) + '</td>'
              + '<td>' + esc(m.designation) + '</td>'
              + '<td><button class="btn-delete" data-name="' + esc(m.name) + '"'
              + ' onclick="deleteEmployee(this.dataset.name)">Remove</button></td>'
              + '</tr>';
    });

    html += '</tbody></table>';
    container.innerHTML = html;
}

// ── Add employee (AJAX) ───────────────────────────────────────────────────
function addEmployee(e) {
    e.preventDefault();
    var name        = document.getElementById('add-name').value.trim();
    var designation = document.getElementById('add-designation').value.trim();

    if (!name || !designation) {
        showAlert('error', 'Please fill in both name and designation.');
        return;
    }

    fetch('employees-api.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'add', name: name, designation: designation })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.error) {
            showAlert('error', data.error);
        } else {
            teamMembers = data.employees;
            rebuildDropdowns();
            renderEmployeeList();
            document.getElementById('add-name').value        = '';
            document.getElementById('add-designation').value = '';
            showAlert('success', name + ' added successfully.');
        }
    })
    .catch(function() { showAlert('error', 'Could not save. Please try again.'); });
}

// ── Delete employee (AJAX) ────────────────────────────────────────────────
function deleteEmployee(name) {
    if (!confirm('Remove "' + name + '" from the team list?')) return;

    fetch('employees-api.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'delete', name: name })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.error) {
            showAlert('error', data.error);
        } else {
            teamMembers = data.employees;
            rebuildDropdowns();
            renderEmployeeList();
            showAlert('success', name + ' removed.');
        }
    })
    .catch(function() { showAlert('error', 'Could not remove. Please try again.'); });
}

// ── Alert banner ──────────────────────────────────────────────────────────
function showAlert(type, msg) {
    var el = document.getElementById('team-alert');
    el.className = 'team-alert ' + type;
    el.textContent = msg;
    el.style.display = 'block';
    clearTimeout(el._timer);
    el._timer = setTimeout(function() { el.style.display = 'none'; }, 3500);
}

// ── HTML escape helper ────────────────────────────────────────────────────
function esc(str) {
    var d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

// ── History ───────────────────────────────────────────────────────────────────
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

function renderHistory(records) {
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
        var period = r.type === 'payslip' ? fmtPeriod(r.pay_period) : (r.letter_date || '');
        html += '<tr>'
              + '<td>' + badge + '</td>'
              + '<td>' + esc(r.employee_name || '') + '</td>'
              + '<td>' + esc(period) + '</td>'
              + '<td>' + esc(r.generated_at || '') + '</td>'
              + '<td style="white-space:nowrap">'
              + '<a href="regenerate.php?id=' + encodeURIComponent(r.id) + '" target="_blank" class="btn-regen">Open</a>'
              + '<button class="btn-delete" data-id="' + esc(r.id) + '" onclick="deleteHistory(this.dataset.id)">Delete</button>'
              + '</td>'
              + '</tr>';
    });
    html += '</tbody></table>';
    el.innerHTML = html;
}

function deleteHistory(id) {
    if (!confirm('Remove this entry from history?')) return;
    fetch('history-api.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'delete', id: id })
    })
    .then(function(r) { return r.json(); })
    .then(function() { loadHistoryTab(); })
    .catch(function() { alert('Could not delete. Please try again.'); });
}

function fmtPeriod(period) {
    if (!period) return '';
    var d = new Date(period + '-01');
    return d.toLocaleString('en-US', { month: 'short', year: 'numeric' });
}

// ── Tab switching ─────────────────────────────────────────────────────────
function showTab(tab, el) {
    document.querySelectorAll('.tab-content').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.nav-item').forEach(function(n) { n.classList.remove('active'); });
    document.getElementById('tab-' + tab).classList.add('active');
    el.classList.add('active');
    var titles = { offer: 'Generate Offer Letter', payslip: 'Generate Payslip', history: 'Document History', team: 'Manage Team' };
    document.getElementById('page-title').textContent = titles[tab] || '';
    if (tab === 'history') loadHistoryTab();
}
</script>

</body>
</html>

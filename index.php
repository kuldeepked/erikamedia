<?php
$today        = date('l, F j, Y');
$default_date = date('Y-m-d');
$default_month = date('Y-m');
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
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0
                         2-2V8z"/><polyline points="14 2 14 8 20 8"/>
                <line x1="16" y1="13" x2="8" y2="13"/>
                <line x1="16" y1="17" x2="8" y2="17"/>
                <polyline points="10 9 9 9 8 9"/>
            </svg>
            Offer Letter
        </a>

        <a class="nav-item" id="nav-payslip" href="#"
           onclick="showTab('payslip', this); return false;">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24">
                <rect x="2" y="5" width="20" height="14" rx="2"/>
                <line x1="2" y1="10" x2="22" y2="10"/>
            </svg>
            Payslip
        </a>

        <a class="nav-item" href="letterhead.php" target="_blank">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"
                 viewBox="0 0 24 24">
                <rect x="3" y="3" width="18" height="18" rx="2"/>
                <line x1="3" y1="8" x2="21" y2="8"/>
                <line x1="3" y1="19" x2="21" y2="19"/>
            </svg>
            Blank Letterhead
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
                    Select the employee and fill in the details below. Click <strong>Generate</strong>
                    to open a print-ready employment agreement in a new tab &mdash; then use
                    <strong>Print &rarr; Save as PDF</strong>.
                </div>

                <form action="generate-offer.php" method="POST" target="_blank">

                    <div class="section-label">Employee Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name *</label>
                            <select name="employee_name" id="offer-name"
                                    onchange="syncOfferDesignation()" required>
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
                            <input type="date" name="letter_date"
                                   value="<?= $default_date ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Start Date *</label>
                            <input type="date" name="start_date"
                                   value="<?= $default_date ?>" required>
                        </div>
                    </div>

                    <div class="section-label">Compensation</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Basic Salary (Rs.) *</label>
                            <input type="number" name="basic_salary" min="0"
                                   placeholder="e.g. 45000" required>
                        </div>
                        <div class="form-group">
                            <label>Allowance (Rs.)</label>
                            <input type="number" name="travel_allowance" min="0"
                                   value="5000">
                        </div>
                    </div>

                    <div class="section-label">Signatory</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Signing Authority Name</label>
                            <input type="text" name="signatory"
                                   value="Kuldeep Kumar">
                        </div>
                    </div>

                    <button type="submit" class="btn-generate">
                        <svg width="16" height="16" fill="none" stroke="currentColor"
                             stroke-width="2" viewBox="0 0 24 24">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12
                                     a2 2 0 0 0 2-2V8z"/>
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
                                    onchange="syncPayslipDesignation()" required>
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
                            <input type="month" name="pay_period"
                                   value="<?= $default_month ?>" required>
                        </div>
                    </div>

                    <div class="section-label">Earnings</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Basic Salary (Rs.) *</label>
                            <input type="number" name="basic_salary" min="0"
                                   placeholder="e.g. 45000" required>
                        </div>
                        <div class="form-group">
                            <label>Allowance (Rs.)</label>
                            <input type="number" name="allowance" min="0"
                                   value="5000">
                        </div>
                        <div class="form-group">
                            <label>Commission (Rs.)</label>
                            <input type="number" name="commission" min="0"
                                   value="0">
                        </div>
                        <div class="form-group">
                            <label>Performer Bonus (Rs.)</label>
                            <input type="number" name="performer_bonus" min="0"
                                   value="0">
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
                    </div>

                    <button type="submit" class="btn-generate">
                        <svg width="16" height="16" fill="none" stroke="currentColor"
                             stroke-width="2" viewBox="0 0 24 24">
                            <rect x="2" y="5" width="20" height="14" rx="2"/>
                            <line x1="2" y1="10" x2="22" y2="10"/>
                        </svg>
                        Generate Payslip
                    </button>
                </form>
            </div>
        </div>

    </div><!-- /content-area -->
</div><!-- /main -->

<script>
// ══════════════════════════════════════════════════════════════════════
//  TEAM MEMBERS — Add your employees here
//  Format: { name: "Full Name", designation: "Job Title" }
// ══════════════════════════════════════════════════════════════════════
const teamMembers = [
    { name: "Zunhara Jamil",  designation: "Reverse Recruiting Agent" },
    { name: "Ali Haider",     designation: "Lead Recruitment Executive" },
    // Add more team members below:
    // { name: "Full Name", designation: "Designation" },
];

// ── Populate dropdowns on page load ──────────────────────────────────
(function () {
    const uniqueDesignations = [...new Set(teamMembers.map(m => m.designation))];

    const offerName = document.getElementById('offer-name');
    const offerPos  = document.getElementById('offer-position');
    const payName   = document.getElementById('payslip-name');
    const payDes    = document.getElementById('payslip-designation');

    teamMembers.forEach(function (m) {
        offerName.add(new Option(m.name, m.name));
        payName.add(new Option(m.name, m.name));
    });

    uniqueDesignations.forEach(function (d) {
        offerPos.add(new Option(d, d));
        payDes.add(new Option(d, d));
    });
})();

// ── Auto-fill designation when name is selected ───────────────────────
function syncOfferDesignation() {
    var selected = document.getElementById('offer-name').value;
    var member   = teamMembers.find(function (m) { return m.name === selected; });
    if (member) {
        document.getElementById('offer-position').value = member.designation;
    }
}

function syncPayslipDesignation() {
    var selected = document.getElementById('payslip-name').value;
    var member   = teamMembers.find(function (m) { return m.name === selected; });
    if (member) {
        document.getElementById('payslip-designation').value = member.designation;
    }
}

// ── Tab switching ─────────────────────────────────────────────────────
function showTab(tab, el) {
    document.querySelectorAll('.tab-content').forEach(function (t) {
        t.classList.remove('active');
    });
    document.querySelectorAll('.nav-item').forEach(function (n) {
        n.classList.remove('active');
    });
    document.getElementById('tab-' + tab).classList.add('active');
    el.classList.add('active');
    var titles = { offer: 'Generate Offer Letter', payslip: 'Generate Payslip' };
    document.getElementById('page-title').textContent = titles[tab];
}
</script>

</body>
</html>

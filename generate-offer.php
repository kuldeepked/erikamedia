<?php
require_once __DIR__ . '/auth.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
verifyCsrf();

$employee_name    = htmlspecialchars(trim($_POST['employee_name']    ?? ''), ENT_QUOTES);
$position         = htmlspecialchars(trim($_POST['position']         ?? 'Reverse Recruiting Agent'), ENT_QUOTES);
$signatory        = htmlspecialchars(trim($_POST['signatory']        ?? 'Kuldeep Kumar'), ENT_QUOTES);
$basic_salary     = (float) ($_POST['basic_salary']     ?? 0);
$travel_allowance = (float) ($_POST['travel_allowance'] ?? 5000);

$letter_date_raw = $_POST['letter_date'] ?? date('Y-m-d');
$start_date_raw  = $_POST['start_date']  ?? date('Y-m-d');

$letter_date    = date('F j, Y', strtotime($letter_date_raw));
$start_date_fmt = date('F j, Y', strtotime($start_date_raw));

$total         = $basic_salary + $travel_allowance;
$salary_fmt    = 'Rs. ' . number_format($basic_salary,     0, '.', ',');
$allowance_fmt = 'Rs. ' . number_format($travel_allowance, 0, '.', ',');
$total_fmt     = 'Rs. ' . number_format($total,            0, '.', ',');

// ── Pick the template by department ──────────────────────────────────────────
$template = match (trim($_POST['position'] ?? '')) {
    'Reverse Recruiting Agent' => 'rra',
    'Quality Assurance'        => 'qa',
    default                    => 'coming-soon',
};

if ($template === 'coming-soon') {
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Template Coming Soon &mdash; Erika Media</title>
    <style>
        body {
            margin: 0; padding: 60px 20px;
            background: #d6dce6;
            font-family: Arial, Helvetica, sans-serif;
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
        }
        .box {
            background: #fff; max-width: 520px; padding: 40px;
            border-radius: 8px; box-shadow: 0 4px 24px rgba(0,0,0,0.18);
            text-align: center;
        }
        .box h1 { color: #0d1b3e; font-size: 22px; margin: 0 0 14px; }
        .box p { color: #444; font-size: 14px; line-height: 1.55; margin: 8px 0; }
        .box strong { color: #0d1b3e; }
        .btn {
            display: inline-block; margin-top: 18px;
            padding: 9px 22px; background: #4a90d9; color: #fff;
            text-decoration: none; border-radius: 5px; font-weight: 600; font-size: 13px;
        }
        .btn:hover { background: #357abd; }
    </style>
</head>
<body>
<div class="box">
    <h1>Offer letter template coming soon</h1>
    <p>There&rsquo;s no offer-letter template for the <strong><?= $position ?></strong> department yet.</p>
    <p>Currently supported: <strong>Reverse Recruiting Agent</strong> and <strong>Quality Assurance</strong>.</p>
    <a class="btn" href="index.php">&larr; Back to dashboard</a>
</div>
</body>
</html><?php
    exit;
}

// ── Save to history ───────────────────────────────────────────────────────────
$_hf   = __DIR__ . '/history.json';
$_hist = file_exists($_hf) ? (json_decode(file_get_contents($_hf), true) ?: []) : [];
array_unshift($_hist, [
    'id'               => uniqid('of_', true),
    'type'             => 'offer',
    'employee_name'    => trim($_POST['employee_name']     ?? ''),
    'position'         => trim($_POST['position']          ?? ''),
    'letter_date'      => $letter_date_raw,
    'start_date'       => $start_date_raw,
    'basic_salary'     => $basic_salary,
    'travel_allowance' => $travel_allowance,
    'signatory'        => trim($_POST['signatory']         ?? 'Kuldeep Kumar'),
    'generated_at'     => date('Y-m-d H:i:s'),
]);
if (count($_hist) > 200) $_hist = array_slice($_hist, 0, 200);
file_put_contents($_hf, json_encode($_hist, JSON_PRETTY_PRINT));
unset($_hf, $_hist);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employment Agreement &mdash; <?= $employee_name ?></title>
    <style>
        /* ── Screen ─────────────────────────────── */
        body {
            margin: 0;
            padding: 30px 20px 60px;
            background: #d6dce6;
            font-family: Arial, Helvetica, sans-serif;
        }

        .action-bar {
            position: fixed;
            top: 0; left: 0; right: 0;
            background: #0d1b3e;
            padding: 10px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            z-index: 999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }

        .action-bar span { color: rgba(255,255,255,0.7); font-size: 13px; font-family: Arial, sans-serif; }
        .action-bar span strong { color: #fff; }

        .btn-print {
            padding: 9px 22px;
            background: #4a90d9;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            font-family: Arial, sans-serif;
            letter-spacing: 0.3px;
        }
        .btn-print:hover { background: #357abd; }

        .pages-wrapper { margin-top: 58px; }

        /* ── A4 Page ─────────────────────────────── */
        .page {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            margin: 0 auto 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.18);
        }

        .page-body { padding: 30px 52px 40px; }

        /* ── Decorative corners ──────────────────── */
        .deco-tr { position: absolute; top: 0; right: 0; width: 170px; height: 120px; pointer-events: none; overflow: hidden; }
        .deco-tr-gray { position: absolute; top: -18px; right: -18px; width: 155px; height: 95px; background: #8a9baa; transform: skewX(-22deg); transform-origin: top right; opacity: 0.75; }
        .deco-tr-teal { position: absolute; top: 12px; right: -8px; width: 120px; height: 78px; background: #1a8e82; transform: skewX(-22deg); transform-origin: top right; }
        .deco-bl { position: absolute; bottom: 0; left: 0; width: 110px; height: 72px; pointer-events: none; overflow: hidden; }
        .deco-bl-teal { position: absolute; bottom: -18px; left: -18px; width: 110px; height: 60px; background: #1a8e82; transform: skewX(-22deg); transform-origin: bottom left; }
        .deco-br { position: absolute; bottom: 0; right: 0; width: 130px; height: 72px; pointer-events: none; overflow: hidden; }
        .deco-br-gray { position: absolute; bottom: -18px; right: -18px; width: 130px; height: 60px; background: #8a9baa; transform: skewX(-22deg); transform-origin: bottom right; opacity: 0.65; }

        /* ── Logo ────────────────────────────────── */
        .logo-wrap { display: inline-block; background: #0d1b3e; padding: 7px 9px; border-radius: 4px; margin-bottom: 6px; }
        .logo-wrap img { height: 56px; width: auto; display: block; }

        /* ── Letter date ─────────────────────────── */
        .letter-date { text-align: right; font-size: 13px; color: #222; margin: 6px 0 16px; }

        /* ── Subject line ────────────────────────── */
        .subject-line { font-size: 13px; margin-bottom: 18px; color: #111; }
        .subject-line u { font-weight: 700; letter-spacing: 0.2px; }

        /* ── Body text ───────────────────────────── */
        .letter-body { font-size: 12px; line-height: 1.5; color: #222; }
        .letter-body p { margin-bottom: 6px; text-align: justify; }
        .letter-body h3 { font-size: 12.5px; font-weight: 700; margin: 10px 0 4px; color: #0d1b3e; }
        .letter-body h4 { font-size: 12px; font-weight: 700; margin: 7px 0 3px; color: #111; display: inline; }
        .greeting { font-size: 13px; font-weight: 700; margin-bottom: 6px; }

        /* Indented paragraphs for numbered sub-points */
        .sub-p {
            display: flex;
            gap: 0;
            margin-bottom: 4px !important;
            text-align: justify;
        }
        .sub-p .sub-num { min-width: 36px; flex-shrink: 0; font-style: normal; }

        /* Bullet list */
        .letter-body ul { margin: 3px 0 6px 20px; padding: 0; }
        .letter-body ul li { margin-bottom: 3px; text-align: justify; }

        /* ── Compensation table ───────────────────── */
        .comp-table { width: 100%; border-collapse: collapse; margin: 5px 0 7px; font-size: 11.5px; }
        .comp-table th { background: #0d1b3e; color: #fff; font-weight: 600; padding: 5px 12px; text-align: left; border: 1px solid #0d1b3e; }
        .comp-table td { padding: 4px 12px; border: 1px solid #ccc; }
        .comp-table .total-row td { font-weight: 700; background: #f0f4f9; }

        /* ── Warning text ────────────────────────── */
        .warning { color: #c0392b; font-weight: 700; }

        /* ── Company signature ───────────────────── */
        .company-sig { margin-top: 16px; font-size: 12px; }
        .company-sig .regards { margin-bottom: 3px; }
        .company-sig .sig-name { font-weight: 700; font-size: 13px; margin-bottom: 22px; }

        .sig-row-pair { display: flex; gap: 60px; margin-top: 0; }
        .sig-col { }
        .sig-line { width: 200px; border-bottom: 1.2px solid #333; height: 18px; display: block; margin-bottom: 4px; }
        .sig-caption { font-size: 10.5px; font-weight: 700; letter-spacing: 0.8px; text-transform: uppercase; color: #444; }

        /* ── Employee Ack page ───────────────────── */
        .ack-intro { font-size: 12.5px; margin: 18px 0 22px; line-height: 1.5; font-weight: 600; }
        .ack-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px 60px; }
        .ack-line { border-bottom: 1.2px solid #333; height: 20px; margin-bottom: 4px; }
        .ack-label { font-size: 10.5px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #444; }
        .ack-full-width { grid-column: 1 / -1; max-width: 260px; }

        /* ── Print styles ────────────────────────── */
        @media print {
            body { background: white; padding: 0; }
            .action-bar { display: none !important; }
            .pages-wrapper { margin-top: 0; }

            /* All .page divs collapse to their content; nothing forces a fresh A4. */
            .page {
                width: 100%;
                min-height: 0;
                margin: 0;
                box-shadow: none;
                overflow: visible;
            }
            .page.cover-page, .page.ack-page { min-height: 0; page-break-after: auto; page-break-before: auto; }

            /* Drop per-.page padding — @page margin handles the printed page edges. */
            .page-body { padding: 0 !important; }

            /* Decorations are a screen flourish — they end up mid-page when content
               flows continuously, so hide them on the printed copy. */
            .deco-tr, .deco-bl, .deco-br { display: none !important; }

            /* Trim ack page intro top margin (was 18mm push-down on screen) */
            .ack-page .page-body { padding-top: 0 !important; }

            @page { size: A4; margin: 14mm 14mm; }
        }
    </style>
</head>
<body>

<div class="action-bar">
    <span>Employment Agreement &mdash; <strong><?= $employee_name ?></strong></span>
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
</div>

<div class="pages-wrapper">

<?php if ($template === 'rra'): ?>
<!-- ══════════════════════ PAGE 1 — Cover ══════════════════════ -->
<div class="page cover-page">
    <div class="deco-tr"><div class="deco-tr-gray"></div><div class="deco-tr-teal"></div></div>
    <div class="deco-bl"><div class="deco-bl-teal"></div></div>
    <div class="deco-br"><div class="deco-br-gray"></div></div>

    <div class="page-body">
        <div class="logo-wrap"><img src="assets/logo.png" alt="Erika Media"></div>
        <div class="letter-date"><?= $letter_date ?></div>

        <div class="subject-line">Re: &nbsp;&nbsp; <u>REVERSE RECRUITING AGENT &mdash; EMPLOYMENT AGREEMENT</u></div>

        <div class="letter-body">
            <p class="greeting">Dear <?= $employee_name ?>,</p>

            <p>We are pleased to extend an offer of employment for the position of <strong><?= $position ?></strong> at Erika Media (hereinafter referred to as the &ldquo;Company&rdquo;). Your employment will commence on <?= $start_date_fmt ?>. This letter sets out the terms and conditions of your employment.</p>

            <h3>1. Scope of Services</h3>
            <p>As a <?= $position ?>, you will be responsible for executing reverse recruiting operations on behalf of the Company&rsquo;s clients. Your duties include, but are not limited to:</p>

            <p class="sub-p"><span class="sub-num">(i)</span><span>Studying each client&rsquo;s profile, career history, and job search goals to ensure all applications are appropriately targeted and customized;</span></p>
            <p class="sub-p"><span class="sub-num">(ii)</span><span>Searching for and identifying relevant job openings that match each client&rsquo;s target roles and preferences;</span></p>
            <p class="sub-p"><span class="sub-num">(iii)</span><span>Preparing and submitting high-quality job applications on behalf of clients, ensuring accuracy and professionalism;</span></p>
            <p class="sub-p"><span class="sub-num">(iv)</span><span>Tracking application statuses, confirmation emails, and recruiter responses;</span></p>
            <p class="sub-p"><span class="sub-num">(v)</span><span>Coordinating with clients and the internal team to facilitate interview scheduling and preparation; and</span></p>
            <p class="sub-p"><span class="sub-num">(vi)</span><span>Performing any additional tasks reasonably related to the reverse recruiting function as assigned by the Company.</span></p>

            <h3>2. Probation Period and Performance Review</h3>

            <p><h4>Initial Review Period.</h4> The first two (2) weeks of your employment will serve as an initial performance review period. During this time, your work will be closely monitored. If your performance is deemed unsatisfactory, the Company reserves the right to terminate your employment immediately without notice.</p>

            <p><h4>Probation Period.</h4> The first three (3) months of your employment will constitute the probation period. During probation, the Company may terminate your employment at any time without prior notice if your performance is found to be below acceptable standards.</p>

            <p><h4>Early Confirmation.</h4> Notwithstanding the above, the Company reserves the right, at its sole discretion, to shorten the probation period&mdash;potentially to as little as one (1) month&mdash;if your performance is deemed exceptional. Any such early confirmation will be communicated to you in writing.</p>

            <p><h4>Transition to Permanent Employment.</h4> Upon successful completion of the probation period, you will be confirmed as a permanent employee of the Company.</p>

            <p><h4>Post-Probation Salary Review.</h4> Upon successful completion of the probation period, your basic salary will be reviewed and may be increased by between ten percent (10%) and twenty percent (20%) based on your overall performance during the probation period. The exact amount of any such increase is at the sole discretion of the Company.</p>
        </div>
    </div>
</div>

<!-- ══════════════════════ PAGE 2 ══════════════════════ -->
<div class="page">
    <div class="page-body">
        <div class="letter-body">

            <h3>3. Compensation</h3>

            <h3 style="font-size:12.5px; margin-top:8px;">3.1 Base Salary and Allowance</h3>
            <table class="comp-table">
                <thead>
                    <tr><th>Component</th><th>Amount (PKR / Month)</th></tr>
                </thead>
                <tbody>
                    <tr><td>Basic Salary</td><td><?= $salary_fmt ?></td></tr>
                    <tr><td>Allowance</td><td><?= $allowance_fmt ?></td></tr>
                    <tr class="total-row"><td><strong>Total Monthly Compensation</strong></td><td><strong><?= $total_fmt ?></strong></td></tr>
                </tbody>
            </table>
            <p>Salary and allowances will be disbursed between the 5th and 10th of each calendar month.</p>

            <h3 style="font-size:12.5px;">3.2 Commission Structure</h3>
            <p>In addition to your base compensation, you will be eligible for the following performance-based commissions:</p>
            <p class="sub-p"><span class="sub-num">1.</span><span><strong>Interview Commission.</strong> You will receive Rs. 1,300 for each client interview secured through your efforts.</span></p>
            <p class="sub-p"><span class="sub-num">2.</span><span><strong>Job Placement Commission.</strong> Upon the successful placement of a client in a job, you will receive a placement commission of no less than Rs. 30,000 per placement. The actual commission amount may be higher depending on the value of the placement and will be determined by the Company on a case-by-case basis. There is no upper cap on placement commissions.</span></p>

            <h3 style="font-size:12.5px;">3.3 Bonuses and Rewards</h3>
            <p class="sub-p"><span class="sub-num">3.</span><span><strong>Punctuality Bonus.</strong> A punctuality bonus of Rs. 5,000 will be awarded monthly to employees who maintain consistent on-time attendance throughout the month, with no unexcused late arrivals and leaves.</span></p>
            <p class="sub-p"><span class="sub-num">4.</span><span><strong>Performer of the Month.</strong> The employee who secures the highest number of client interviews in a given month will be recognized as the Performer of the Month and will receive a reward of Rs. 5,000. Criteria and reward amounts are at the sole discretion of the Company.</span></p>

            <h3>4. Attendance and Leave Policy</h3>

            <h3 style="font-size:12.5px;">4.1 During Probation</h3>
            <p>During the probation period, taking days off is strongly discouraged and will only be permitted in cases of genuine emergency, subject to prior approval by the Company.</p>

            <h3 style="font-size:12.5px;">4.2 After Probation (Permanent Employment)</h3>
            <ul>
                <li>You are entitled to two (2) paid leave days per month.</li>
                <li>If you take more than two (2) days of leave in a month, salary will be deducted proportionally for each additional day.</li>
                <li>Taking leave on the day immediately following a public holiday will result in all intervening public holidays being treated as unpaid leave, with corresponding salary deductions.</li>
                <li>Two (2) late arrivals per month are permitted. A third late arrival will be counted as one day of absence and will be subject to deduction if applicable.</li>
                <li>You must inform the Company in advance before taking any leave or holiday so that your clients may be temporarily reassigned to ensure uninterrupted service delivery.</li>
            </ul>

            <h3>5. Notice Period</h3>
            <p>Should you wish to resign from your position, you are required to provide a minimum of fifteen (15) calendar days&rsquo; written notice to the Company. During the notice period, you must continue to perform your duties in full. Failure to serve the complete notice period may result in forfeiture of any outstanding salary or commission payments for the notice period.</p>

            <h3>6. Confidentiality</h3>
            <p>You agree to keep all client information, internal processes, company data, proprietary methods, operational details, client lists, recruiting strategies, and any other information related to the Company or its clients strictly confidential during and after the term of your employment.</p>
            <p>You shall not, under any circumstances, share, disclose, discuss, publish, or otherwise make available&mdash;whether verbally, in writing, electronically, on social media, or by any other means&mdash;any information relating to the Company, its clients, its processes, or its operations to any third party. This obligation applies without limitation during your employment and continues indefinitely after the termination of your employment.</p>
            <p><span class="warning">WARNING:</span> Any breach of this confidentiality obligation will result in immediate termination of your employment and will expose you to legal action, including but not limited to claims for damages, injunctive relief, and any other remedies available under applicable law. The Company reserves the right to pursue all legal consequences to the fullest extent permitted by law.</p>

        </div>
    </div>
</div>

<!-- ══════════════════════ PAGE 3 ══════════════════════ -->
<div class="page">
    <div class="page-body">
        <div class="letter-body">

            <h3>7. Intellectual Property</h3>
            <p>All work product, processes, templates, strategies, and materials created by you during the course of your employment shall be the exclusive property of the Company.</p>

            <h3>8. Termination</h3>
            <ul>
                <li><strong>During Probation.</strong> The Company may terminate your employment at any time during the probation period without prior notice if your performance is found to be unsatisfactory.</li>
                <li><strong>After Probation.</strong> The Company may terminate your employment by providing fifteen (15) calendar days&rsquo; written notice or payment in lieu of notice.</li>
                <li><strong>Cause.</strong> The Company reserves the right to terminate your employment immediately, without notice, in cases of gross misconduct, breach of confidentiality, fraud, fabrication of application records, or any other act that materially harms the Company or its clients.</li>
            </ul>

            <h3>9. Governing Law</h3>
            <p>This agreement shall be governed by and construed in accordance with the laws of Pakistan. Any disputes arising under this agreement shall be subject to the exclusive jurisdiction of the courts of Pakistan.</p>

            <h3>10. General Provisions</h3>
            <p>This letter constitutes the entire agreement between you and the Company regarding the subject matter described herein and supersedes all prior representations, understandings, and agreements. The terms and conditions of this agreement may not be amended except by a writing signed by both parties. If any provision of this agreement is declared illegal, unenforceable, or ineffective, such provision shall be deemed severable and the remainder of this agreement shall remain valid and binding.</p>

            <!-- Company signature -->
            <div class="company-sig" style="margin-top: 32px;">
                <p class="regards">Sincerely,</p>
                <p class="sig-name"><?= $signatory ?></p>
                <div class="sig-row-pair">
                    <div class="sig-col">
                        <span class="sig-line"></span>
                        <span class="sig-caption">Signature</span>
                    </div>
                    <div class="sig-col">
                        <span class="sig-line"></span>
                        <span class="sig-caption">Date</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php elseif ($template === 'qa'): ?>
<!-- ══════════════════════ PAGE 1 — Cover (QA) ══════════════════════ -->
<div class="page cover-page">
    <div class="deco-tr"><div class="deco-tr-gray"></div><div class="deco-tr-teal"></div></div>
    <div class="deco-bl"><div class="deco-bl-teal"></div></div>
    <div class="deco-br"><div class="deco-br-gray"></div></div>

    <div class="page-body">
        <div class="logo-wrap"><img src="assets/logo.png" alt="Erika Media"></div>
        <div class="letter-date"><?= $letter_date ?></div>

        <div class="subject-line">Re: &nbsp;&nbsp; <u>QUALITY ASSURANCE AGENT &mdash; EMPLOYMENT AGREEMENT</u></div>

        <div class="letter-body">
            <p class="greeting">Dear <?= $employee_name ?>,</p>

            <p>We are pleased to extend an offer of employment for the position of <strong>Quality Assurance (QA) Agent</strong> at Erika Media (hereinafter referred to as the &ldquo;Company&rdquo;). Your employment will commence on <?= $start_date_fmt ?>. This letter sets out the terms and conditions of your employment.</p>

            <h3>1. Scope of Services</h3>
            <p>As a Quality Assurance Agent, you will be responsible for auditing and reviewing the work produced by the Company&rsquo;s reverse recruiting team to ensure accuracy, integrity, and adherence to client requirements. Your duties include, but are not limited to:</p>

            <p class="sub-p"><span class="sub-num">(i)</span><span>Reviewing job applications submitted by the team on a daily basis to verify that each job matches the client&rsquo;s preferences as recorded in their Profile Notes in the Dashboard, including location, work arrangement, salary range, employment type, and target job titles;</span></p>
            <p class="sub-p"><span class="sub-num">(ii)</span><span>Auditing client CVs and resumes to confirm they are clean, professional, free of misrepresentation, and properly aligned with the job descriptions being applied to;</span></p>
            <p class="sub-p"><span class="sub-num">(iii)</span><span>Reviewing application form screenshots submitted by the team to confirm that all personal information, contact details, work experience, education, sponsorship status, and other fields have been filled in correctly and honestly, in line with the client&rsquo;s Profile Notes;</span></p>
            <p class="sub-p"><span class="sub-num">(iv)</span><span>Identifying inflated job titles, fabricated experience, inconsistent dates or formatting, spelling errors, and any other deviations from client-provided information, and ensuring nothing is misrepresented on the client&rsquo;s behalf;</span></p>
            <p class="sub-p"><span class="sub-num">(v)</span><span>Periodically reviewing client emails and LinkedIn activity managed by the team where required, to verify that communications and outreach align with client preferences and Company standards;</span></p>
            <p class="sub-p"><span class="sub-num">(vi)</span><span>Logging all errors, omissions, and quality issues clearly in the designated Dashboard section so the application team can correct them and avoid repetition; and</span></p>
            <p class="sub-p"><span class="sub-num">(vii)</span><span>Performing any additional tasks reasonably related to the quality assurance function as assigned by the Company.</span></p>

            <h3>2. Probation Period and Performance Review</h3>

            <p><h4>Initial Review Period.</h4> The first two (2) weeks of your employment will serve as an initial performance review period. During this time, your work will be closely monitored. If your performance is deemed unsatisfactory, the Company reserves the right to terminate your employment immediately without notice.</p>

            <p><h4>Probation Period.</h4> The first three (3) months of your employment will constitute the probation period. During probation, the Company may terminate your employment at any time without prior notice if your performance is found to be below acceptable standards.</p>

            <p><h4>Early Confirmation.</h4> Notwithstanding the above, the Company reserves the right, at its sole discretion, to shorten the probation period&mdash;potentially to as little as one (1) month&mdash;if your performance is deemed exceptional. Any such early confirmation will be communicated to you in writing.</p>

            <p><h4>Transition to Permanent Employment.</h4> Upon successful completion of the probation period, you will be confirmed as a permanent employee of the Company.</p>

            <p><h4>Post-Probation Salary Review.</h4> Upon successful completion of the probation period, your basic salary will be reviewed and may be increased by between ten percent (10%) and twenty percent (20%) based on your overall performance during the probation period. The exact amount of any such increase is at the sole discretion of the Company.</p>
        </div>
    </div>
</div>

<!-- ══════════════════════ PAGE 2 (QA) ══════════════════════ -->
<div class="page">
    <div class="page-body">
        <div class="letter-body">

            <h3>3. Compensation</h3>

            <h3 style="font-size:12.5px; margin-top:8px;">3.1 Base Salary and Allowance</h3>
            <table class="comp-table">
                <thead>
                    <tr><th>Component</th><th>Amount (PKR / Month)</th></tr>
                </thead>
                <tbody>
                    <tr><td>Basic Salary</td><td><?= $salary_fmt ?></td></tr>
                    <tr><td>Allowance</td><td><?= $allowance_fmt ?></td></tr>
                    <tr class="total-row"><td><strong>Total Monthly Compensation</strong></td><td><strong><?= $total_fmt ?></strong></td></tr>
                </tbody>
            </table>
            <p>Salary and allowances will be disbursed between the 5th and 10th of each calendar month.</p>

            <h3 style="font-size:12.5px;">3.2 Punctuality Bonus</h3>
            <p>A punctuality bonus of Rs. 5,000 will be awarded monthly to employees who maintain consistent on-time attendance throughout the month, with no unexcused late arrivals and no unapproved leaves. Eligibility and assessment are at the sole discretion of the Company.</p>
            <p>No commission or placement-based incentives are payable for the Quality Assurance Agent role.</p>

            <h3>4. Attendance and Leave Policy</h3>

            <h3 style="font-size:12.5px;">4.1 During Probation</h3>
            <p>During the probation period, taking days off is strongly discouraged and will only be permitted in cases of genuine emergency, subject to prior approval by the Company.</p>

            <h3 style="font-size:12.5px;">4.2 After Probation (Permanent Employment)</h3>
            <ul>
                <li>You are entitled to two (2) paid leave days per month.</li>
                <li>If you take more than two (2) days of leave in a month, salary will be deducted proportionally for each additional day.</li>
                <li>Taking leave on the day immediately following a public holiday will result in all intervening public holidays being treated as unpaid leave, with corresponding salary deductions.</li>
                <li>Two (2) late arrivals per month are permitted. A third late arrival will be counted as one day of absence and will be subject to deduction if applicable.</li>
                <li>You must inform the Company in advance before taking any leave or holiday so that QA coverage may be temporarily reassigned to ensure uninterrupted service delivery.</li>
            </ul>

            <h3>5. Notice Period</h3>
            <p>Should you wish to resign from your position, you are required to provide a minimum of fifteen (15) calendar days&rsquo; written notice to the Company. During the notice period, you must continue to perform your duties in full. Failure to serve the complete notice period may result in forfeiture of any outstanding salary or bonus payments for the notice period.</p>

            <h3>6. Confidentiality</h3>
            <p>You agree to keep all client information, internal processes, company data, proprietary methods, operational details, client lists, QA frameworks, recruiting strategies, and any other information related to the Company or its clients strictly confidential during and after the term of your employment.</p>
            <p>You shall not, under any circumstances, share, disclose, discuss, publish, or otherwise make available&mdash;whether verbally, in writing, electronically, on social media, or by any other means&mdash;any information relating to the Company, its clients, its processes, or its operations to any third party. This obligation applies without limitation during your employment and continues indefinitely after the termination of your employment.</p>
            <p><span class="warning">WARNING:</span> Any breach of this confidentiality obligation will result in immediate termination of your employment and will expose you to legal action, including but not limited to claims for damages, injunctive relief, and any other remedies available under applicable law. The Company reserves the right to pursue all legal consequences to the fullest extent permitted by law.</p>

        </div>
    </div>
</div>

<!-- ══════════════════════ PAGE 3 (QA) ══════════════════════ -->
<div class="page">
    <div class="page-body">
        <div class="letter-body">

            <h3>7. Intellectual Property</h3>
            <p>All work product, QA checklists, audit logs, processes, templates, and materials created by you during the course of your employment shall be the exclusive property of the Company.</p>

            <h3>8. Termination</h3>
            <ul>
                <li><strong>During Probation.</strong> The Company may terminate your employment at any time during the probation period without prior notice if your performance is found to be unsatisfactory.</li>
                <li><strong>After Probation.</strong> The Company may terminate your employment by providing fifteen (15) calendar days&rsquo; written notice or payment in lieu of notice.</li>
                <li><strong>Cause.</strong> The Company reserves the right to terminate your employment immediately, without notice, in cases of gross misconduct, breach of confidentiality, fraud, falsification of audit records, deliberately approving applications containing misrepresented client information, or any other act that materially harms the Company or its clients.</li>
            </ul>

            <h3>9. Governing Law</h3>
            <p>This agreement shall be governed by and construed in accordance with the laws of Pakistan. Any disputes arising under this agreement shall be subject to the exclusive jurisdiction of the courts of Pakistan.</p>

            <h3>10. General Provisions</h3>
            <p>This letter constitutes the entire agreement between you and the Company regarding the subject matter described herein and supersedes all prior representations, understandings, and agreements. The terms and conditions of this agreement may not be amended except by a writing signed by both parties. If any provision of this agreement is declared illegal, unenforceable, or ineffective, such provision shall be deemed severable and the remainder of this agreement shall remain valid and binding.</p>

            <!-- Company signature -->
            <div class="company-sig" style="margin-top: 32px;">
                <p class="regards">Sincerely,</p>
                <p class="sig-name"><?= $signatory ?></p>
                <div class="sig-row-pair">
                    <div class="sig-col">
                        <span class="sig-line"></span>
                        <span class="sig-caption">Signature</span>
                    </div>
                    <div class="sig-col">
                        <span class="sig-line"></span>
                        <span class="sig-caption">Date</span>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<?php endif; ?>

<!-- ══════════════════════ PAGE 4 — Employee Acknowledgment ══════════════════════ -->
<div class="page ack-page">
    <div class="deco-tr"><div class="deco-tr-gray"></div><div class="deco-tr-teal"></div></div>
    <div class="deco-bl"><div class="deco-bl-teal"></div></div>
    <div class="deco-br"><div class="deco-br-gray"></div></div>

    <div class="page-body" style="padding-top: 50px;">
        <div class="letter-body">
            <h3 style="margin-bottom: 14px; font-size: 14px;">Employee Acknowledgment</h3>
            <p class="ack-intro">I acknowledge and accept the terms and conditions of this employment agreement:</p>

            <div class="ack-grid">
                <div>
                    <div class="ack-line"></div>
                    <div class="ack-label">Full Legal Name</div>
                </div>
                <div>
                    <div class="ack-line"></div>
                    <div class="ack-label">Signature</div>
                </div>
                <div>
                    <div class="ack-line"></div>
                    <div class="ack-label">Full Mailing Address</div>
                </div>
                <div>
                    <div class="ack-line"></div>
                    <div class="ack-label">Date</div>
                </div>
                <div class="ack-full-width">
                    <div class="ack-line"></div>
                    <div class="ack-label">Phone Number</div>
                </div>
            </div>
        </div>
    </div>
</div>

</div><!-- /pages-wrapper -->
</body>
</html>

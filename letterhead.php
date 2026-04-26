<?php
require_once __DIR__ . '/auth.php';
requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Erika Media &mdash; Blank Letterhead</title>
    <style>
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

        .action-bar span { color: rgba(255,255,255,0.7); font-size: 13px; }
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

        /* ── A4 Page ─────────────────────────── */
        .page {
            width: 210mm;
            min-height: 297mm;
            background: #fff;
            margin: 0 auto;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 24px rgba(0,0,0,0.18);
            display: flex;
            flex-direction: column;
        }

        /* ── Decorative corners ──────────────── */
        .deco-tr { position: absolute; top: 0; right: 0; width: 170px; height: 120px; pointer-events: none; overflow: hidden; }
        .deco-tr-gray { position: absolute; top: -18px; right: -18px; width: 155px; height: 95px; background: #8a9baa; transform: skewX(-22deg); transform-origin: top right; opacity: 0.75; }
        .deco-tr-teal { position: absolute; top: 12px; right: -8px; width: 120px; height: 78px; background: #1a8e82; transform: skewX(-22deg); transform-origin: top right; }
        .deco-bl { position: absolute; bottom: 0; left: 0; width: 110px; height: 72px; pointer-events: none; overflow: hidden; }
        .deco-bl-teal { position: absolute; bottom: -18px; left: -18px; width: 110px; height: 60px; background: #1a8e82; transform: skewX(-22deg); transform-origin: bottom left; }
        .deco-br { position: absolute; bottom: 0; right: 0; width: 130px; height: 72px; pointer-events: none; overflow: hidden; }
        .deco-br-gray { position: absolute; bottom: -18px; right: -18px; width: 130px; height: 60px; background: #8a9baa; transform: skewX(-22deg); transform-origin: bottom right; opacity: 0.65; }

        /* ── Header ──────────────────────────── */
        .lh-header {
            padding: 28px 50px 20px;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            position: relative;
            z-index: 5;
        }

        .logo-wrap {
            display: inline-block;
            background: #0d1b3e;
            padding: 7px 9px;
            border-radius: 4px;
        }
        .logo-wrap img { height: 56px; width: auto; display: block; }

        .company-info {
            text-align: right;
            font-family: Arial, sans-serif;
        }
        .company-info .co-name {
            font-size: 15px;
            font-weight: 700;
            color: #0d1b3e;
            letter-spacing: 0.3px;
        }
        .company-info .co-tagline {
            font-size: 10px;
            color: #1a8e82;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            margin-top: 3px;
        }

        /* ── Divider line ────────────────────── */
        .lh-divider {
            height: 2px;
            background: linear-gradient(to right, #0d1b3e 60%, #1a8e82 100%);
            margin: 0 50px;
        }

        /* ── Blank body ──────────────────────── */
        .lh-body { flex: 1; padding: 20px 50px 0; }

        /* ── Footer ──────────────────────────── */
        .lh-footer {
            margin: 0 50px;
            padding: 14px 0 28px;
            border-top: 1px solid #ccc;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            font-size: 11px;
            color: #444;
            font-family: Arial, sans-serif;
            position: relative;
            z-index: 5;
        }

        .footer-col { display: flex; align-items: flex-start; gap: 7px; }
        .footer-icon { font-size: 13px; margin-top: 1px; flex-shrink: 0; }
        .footer-label { font-weight: 700; display: block; margin-bottom: 1px; font-size: 9.5px; letter-spacing: 0.5px; text-transform: uppercase; color: #0d1b3e; }

        /* ── Print ───────────────────────────── */
        @media print {
            body { background: white; padding: 0; }
            .action-bar { display: none !important; }
            .pages-wrapper { margin-top: 0; }
            .page { width: 100%; margin: 0; box-shadow: none; }
            @page { size: A4; margin: 0; }
        }
    </style>
</head>
<body>

<div class="action-bar">
    <span><strong>Erika Media</strong> &mdash; Blank Letterhead</span>
    <button class="btn-print" onclick="window.print()">&#128438; Print / Save as PDF</button>
</div>

<div class="pages-wrapper">
<div class="page">
    <!-- Decorative corners -->
    <div class="deco-tr"><div class="deco-tr-gray"></div><div class="deco-tr-teal"></div></div>
    <div class="deco-bl"><div class="deco-bl-teal"></div></div>
    <div class="deco-br"><div class="deco-br-gray"></div></div>

    <!-- Header -->
    <div class="lh-header">
        <div class="logo-wrap">
            <img src="assets/logo.png" alt="Erika Media">
        </div>
        <div class="company-info">
            <div class="co-name">Erika Media</div>
            <div class="co-tagline">Where Technology Meets Creativity</div>
        </div>
    </div>

    <!-- Gradient divider -->
    <div class="lh-divider"></div>

    <!-- Blank content area -->
    <div class="lh-body"></div>

    <!-- Footer -->
    <div class="lh-footer">
        <div class="footer-col">
            <span class="footer-icon">&#128205;</span>
            <div>
                <span class="footer-label">Address</span>
                Office No. 505, 5th Floor, Kashif Center,<br>
                Sharah-e-Faisal, Karachi
            </div>
        </div>
        <div class="footer-col">
            <span class="footer-icon">&#128222;</span>
            <div>
                <span class="footer-label">Contact</span>
                0334-2123573
            </div>
        </div>
        <div class="footer-col">
            <span class="footer-icon">&#127760;</span>
            <div>
                <span class="footer-label">Website</span>
                www.erikamedia.com
            </div>
        </div>
    </div>
</div>
</div>

</body>
</html>

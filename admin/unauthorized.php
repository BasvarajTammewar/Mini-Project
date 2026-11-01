<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 Forbidden — Access Denied | CareerAct</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #F3F6FA; color: #17243B; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 24px; box-sizing: border-box; }
        .card { background: #fff; padding: 48px 40px; border-radius: 16px; box-shadow: 0 16px 40px -10px rgba(22, 58, 112, 0.12); border: 1px solid #E4EAF2; text-align: center; max-width: 500px; width: 100%; }
        .icon-wrap { width: 64px; height: 64px; margin: 0 auto 20px; background: #FEF2F2; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #DC2626; }
        .icon-wrap svg { width: 32px; height: 32px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        h1 { font-size: 22px; font-weight: 700; color: #17243B; margin-bottom: 12px; }
        p { color: #667085; font-size: 15px; line-height: 1.6; margin-bottom: 28px; }
        .btn-group { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; justify-content: center; background: #1769E8; color: #fff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; transition: background 0.2s; }
        .btn:hover { background: #1255be; }
        .btn-outline { background: transparent; color: #1769E8; border: 1px solid #1769E8; }
        .btn-outline:hover { background: #EFF6FF; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-wrap">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
        </div>
        <h1>403 — Unauthorized Access</h1>
        <p>You do not have administrative privileges to view this section. Please return to your student dashboard or sign in with an authorized account.</p>
        <div class="btn-group">
            <a href="../dashboard.php" class="btn">Student Dashboard</a>
            <a href="../logout.php" class="btn btn-outline">Sign Out</a>
        </div>
    </div>
</body>
</html>

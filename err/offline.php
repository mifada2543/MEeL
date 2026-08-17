<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline — MEeL</title>
    <meta name="theme-color" content="#05070c">
    <style>        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: ui-monospace, 'Cascadia Code', 'JetBrains Mono', 'SF Mono', Monaco, Consolas, monospace;
            background: #05070c;
            color: #9ca3af;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .container {
            text-align: center;
            max-width: 480px;
        }
        .icon {
            font-size: 4rem;
            margin-bottom: 1.5rem;
            display: block;
        }
        h1 {
            color: #f97316;
            font-size: 1.25rem;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            margin-bottom: 1rem;
        }
        p {
            font-size: 0.8rem;
            line-height: 1.7;
            color: #6b7280;
            margin-bottom: 2rem;
        }
        .status {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 9999px;
            background: rgba(249, 115, 22, 0.1);
            border: 1px solid rgba(249, 115, 22, 0.2);
            color: #f97316;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            margin-bottom: 2rem;
        }
        .btn {
            display: inline-block;
            padding: 0.625rem 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            color: #d1d5db;
            font-size: 0.75rem;
            font-weight: 700;
            text-decoration: none;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            transition: all 0.2s ease;
        }
        .btn:hover {
            background: rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .btn-primary {
            background: #f97316;
            border-color: #f97316;
            color: #fff;
        }
        .btn-primary:hover {
            background: #ea580c;
        }
        .footer {
            margin-top: 3rem;
            font-size: 0.6rem;
            color: #374151;
            letter-spacing: 0.05em;
        }
        .dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #f97316;
            animation: pulse 2s ease-in-out infinite;
            margin-right: 0.5rem;
            vertical-align: middle;
        }
        @keyframes pulse {
            0%, 100% { opacity: 0.3; }
            50% { opacity: 1; }
        }
</style>
</head>
<body>
    <div class="container">
        <span class="icon">📡</span>
        <div class="status">
            <span class="dot"></span>
            Offline Mode
        </div>
        <h1>Koneksi Terputus</h1>
        <p>
            Kamu sedang offline. Halaman yang diminta tidak tersedia di cache.<br>
            Silakan periksa koneksi internet dan coba lagi.
        </p>
        <a href="javascript:location.reload()" class="btn btn-primary">Coba Lagi</a>
        <a href="../" class="btn" style="margin-left:0.5rem">Ke Halaman Utama</a>
        <div class="footer">MEeL &mdash; Media Hub Platform</div>
    </div>
</body>
</html>

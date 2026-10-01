<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FSMS Login - Tharimpepe Feeding Scheme</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/fsms-ui.css">
    <style>
        body {
            background: #f3f6fa;
            color: #071326;
            font-family: Inter, Arial, sans-serif;
            min-height: 100vh;
        }

        .login-shell {
            display: grid;
            grid-template-columns: minmax(0, 1.16fr) minmax(420px, 0.84fr);
            min-height: 100vh;
        }

        .login-mission {
            background: #1b3a5c;
            color: #fff;
            min-height: 100vh;
            overflow: hidden;
            position: relative;
        }

        .login-slide {
            inset: 0;
            opacity: 0;
            position: absolute;
            transition: opacity 700ms ease;
            visibility: hidden;
        }

        .login-slide.is-active {
            opacity: 1;
            visibility: visible;
            z-index: 1;
        }

        .login-slide img {
            height: 100%;
            object-fit: cover;
            width: 100%;
        }

        .login-mission::after {
            background: linear-gradient(180deg, rgba(9, 25, 31, .3), transparent 36%, rgba(9, 25, 31, .64));
            content: "";
            inset: 0;
            pointer-events: none;
            position: absolute;
            z-index: 2;
        }

        .login-mission-brand,
        .login-mission-caption,
        .login-carousel-controls {
            position: absolute;
            z-index: 3;
        }

        .login-mission-brand {
            align-items: center;
            display: flex;
            gap: 12px;
            left: 36px;
            top: 30px;
        }

        .login-mission-brand img {
            background: rgba(255,255,255,.92);
            border-radius: 10px;
            height: 44px;
            object-fit: contain;
            padding: 4px;
            width: 58px;
        }

        .login-mission-brand strong,
        .login-mission-brand span { display: block; }
        .login-mission-brand strong { font-size: 15px; }
        .login-mission-brand span { color: rgba(255,255,255,.82); font-size: 12px; margin-top: 2px; }

        .login-mission-caption {
            bottom: 76px;
            left: 36px;
            max-width: min(70%, 560px);
        }

        .login-mission-caption h1 {
            font-size: clamp(24px, 3vw, 38px);
            font-weight: 700;
            line-height: 1.12;
            margin-bottom: 10px;
        }

        .login-mission-caption p { color: rgba(255,255,255,.9); font-size: 15px; line-height: 1.5; margin: 0; }

        .login-carousel-controls {
            align-items: center;
            bottom: 32px;
            display: flex;
            gap: 10px;
            left: 36px;
        }

        .login-carousel-arrow,
        .login-carousel-dot {
            align-items: center;
            background: rgba(255,255,255,.24);
            border: 1px solid rgba(255,255,255,.58);
            color: #fff;
            cursor: pointer;
            display: inline-flex;
            justify-content: center;
        }

        .login-carousel-arrow {
            border-radius: 50%;
            font-size: 20px;
            height: 36px;
            line-height: 1;
            width: 36px;
        }

        .login-carousel-dots { align-items: center; display: flex; gap: 8px; margin: 0 4px; }

        .login-carousel-dot {
            border: 0;
            border-radius: 99px;
            height: 9px;
            opacity: .72;
            padding: 0;
            transition: width 180ms ease, opacity 180ms ease;
            width: 9px;
        }

        .login-carousel-dot[aria-current="true"] { background: #fff; opacity: 1; width: 28px; }
        .login-carousel-arrow:hover,
        .login-carousel-arrow:focus-visible,
        .login-carousel-dot:focus-visible { outline: 3px solid rgba(255,255,255,.7); outline-offset: 3px; }

        @media (prefers-reduced-motion: reduce) {
            .login-slide, .login-carousel-dot { transition: none; }
        }

        .login-panel {
            align-items: center;
            background: #f4f7fb;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 48px 32px;
        }

        .login-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 16px 28px rgba(17, 24, 39, 0.12);
            max-width: 448px;
            padding: 36px 32px;
            width: 100%;
        }

        .login-header {
            margin-bottom: 28px;
            text-align: center;
        }

        .login-header h2 {
            color: #071326;
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        .login-header p {
            color: #4b5563;
            font-size: 14px;
            margin: 0;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            color: #111827;
            display: block;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 9px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            color: #94a3b8;
            font-size: 18px;
            left: 16px;
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
        }

        .login-card .form-control {
            border: 1px solid #cfd6e1;
            border-radius: 10px;
            font-size: 16px;
            min-height: 50px;
            padding: 12px 16px 12px 42px;
        }

        .login-card .form-control::placeholder {
            color: #8da0ba;
        }

        .btn-login {
            background: #1b3a5c;
            border: 0;
            border-radius: 8px;
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            margin-top: 6px;
            min-height: 48px;
            width: 100%;
        }

        .btn-login:hover {
            background: #2e4a6c;
        }

        .form-footer {
            margin-top: 26px;
            text-align: center;
        }

        .form-footer a {
            color: #1b3a5c;
            font-size: 14px;
            text-decoration: none;
        }

        .form-footer a:hover {
            text-decoration: underline;
        }

        .quick-donate {
            display: block;
            background: #168447;
            border-radius: 8px;
            color: #fff !important;
            font-size: 15px !important;
            font-weight: 700;
            margin-top: 14px;
            padding: 12px;
            text-decoration: none !important;
        }

        .login-footer {
            color: #46566b;
            font-size: 12px;
            margin-top: 26px;
            text-align: center;
        }

        .alert-error,
        .logout-success {
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            padding: 11px 12px;
        }

        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }

        .logout-success {
            background: #ecfdf5;
            border: 1px solid #bbf7d0;
            color: #166534;
        }

        @media (max-width: 900px) {
            .login-shell {
                grid-template-columns: 1fr;
            }

            .login-mission {
                min-height: 320px;
            }

            .login-mission-caption {
                bottom: 72px;
                left: 24px;
                max-width: 80%;
            }

            .login-mission-brand { left: 24px; top: 22px; }
            .login-carousel-controls { bottom: 22px; left: 24px; }

            .login-panel {
                min-height: 560px;
            }
        }

        @media (max-width: 520px) {
            .login-mission { min-height: 270px; }
            .login-mission-caption { bottom: 66px; }
            .login-mission-caption h1 { font-size: 25px; }
            .login-mission-caption p { font-size: 13px; }
            .login-mission-brand img { height: 38px; width: 50px; }
            .login-mission-brand { font-size: 13px; }
            .login-carousel-controls { bottom: 17px; }
            .login-carousel-arrow { height: 32px; width: 32px; }
            .login-card {
                padding: 30px 20px;
            }
        }
    </style>
</head>
<body>
    <main class="login-shell">
        <section class="login-mission" aria-label="Tharimpepe community stories" data-login-carousel>
            <div class="login-slide is-active" data-slide aria-hidden="false">
                <img src="/assets/images/login-carousel/community.svg" alt="Illustration of community members sharing a meal" fetchpriority="high">
            </div>
            <div class="login-slide" data-slide aria-hidden="true">
                <img src="/assets/images/login-carousel/meals.svg" alt="Illustration of a nutritious meal prepared for the community" loading="lazy">
            </div>
            <div class="login-slide" data-slide aria-hidden="true">
                <img src="/assets/images/login-carousel/together.svg" alt="Illustration of volunteers preparing food together" loading="lazy">
            </div>
            <div class="login-mission-brand" aria-hidden="true">
                <img src="/assets/images/fsmslogo.png" alt="" width="58" height="44">
                <div><strong>Tharimpepe</strong><span>Feeding Scheme</span></div>
            </div>
            <div class="login-mission-caption" aria-live="polite" aria-atomic="true">
                <h1 data-slide-title>Our community, growing together</h1>
                <p data-slide-copy>Working together to make every meal count.</p>
            </div>
            <div class="login-carousel-controls">
                <button class="login-carousel-arrow" type="button" data-carousel-prev aria-label="Previous image">&#8249;</button>
                <div class="login-carousel-dots" role="group" aria-label="Choose a community story">
                    <button class="login-carousel-dot" type="button" aria-label="Show community story 1" aria-current="true" data-carousel-dot="0"></button>
                    <button class="login-carousel-dot" type="button" aria-label="Show community story 2" aria-current="false" data-carousel-dot="1"></button>
                    <button class="login-carousel-dot" type="button" aria-label="Show community story 3" aria-current="false" data-carousel-dot="2"></button>
                </div>
                <button class="login-carousel-arrow" type="button" data-carousel-next aria-label="Next image">&#8250;</button>
            </div>
        </section>

        <section class="login-panel" aria-label="Sign in">
            <div class="login-card">
                <div class="login-header">
                    <h2>Welcome Back</h2>
                    <p>Sign in to access the admin portal</p>
                </div>

                <?php if (isset($_GET['logout']) && $_GET['logout'] === 'success'): ?>
                    <div class="logout-success" role="status">
                        <i class="fas fa-check-circle me-2" aria-hidden="true"></i>
                        You have been logged out successfully.
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert-error" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/index.php?action=login">
                    <?php echo csrfTokenInput(); ?>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <i class="far fa-user input-icon" aria-hidden="true"></i>
                            <input
                                type="text"
                                class="form-control"
                                id="username"
                                name="username"
                                placeholder="Enter your username"
                                required
                                autocomplete="username"
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <i class="fas fa-lock input-icon" aria-hidden="true"></i>
                            <input
                                type="password"
                                class="form-control"
                                id="password"
                                name="password"
                                placeholder="Enter your password"
                                required
                                autocomplete="current-password"
                            >
                        </div>
                    </div>

                    <button type="submit" class="btn-login">Login</button>
                </form>

                <div class="form-footer">
                    <a href="/index.php?action=register">Don't have an account? Register here</a>
                    <a class="quick-donate" href="/donate.php"><i class="fas fa-hand-holding-heart me-2" aria-hidden="true"></i>Quick Donate as Guest</a>
                </div>
            </div>

            <div class="login-footer">
                © 2026 Tharimpepe Feeding Scheme. All rights reserved.
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (() => {
            const carousel = document.querySelector('[data-login-carousel]');
            if (!carousel) return;

            const slides = Array.from(carousel.querySelectorAll('[data-slide]'));
            const dots = Array.from(carousel.querySelectorAll('[data-carousel-dot]'));
            const title = carousel.querySelector('[data-slide-title]');
            const copy = carousel.querySelector('[data-slide-copy]');
            const captions = [
                ['Our community, growing together', 'Working together to make every meal count.'],
                ['Nourishment for brighter days', 'Fresh ingredients help us serve balanced meals.'],
                ['Many hands, one caring community', 'Volunteers make the feeding scheme possible.']
            ];
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
            let current = 0;
            let timer = null;
            let touchStartX = null;

            const showSlide = (index) => {
                current = (index + slides.length) % slides.length;
                slides.forEach((slide, i) => {
                    const active = i === current;
                    slide.classList.toggle('is-active', active);
                    slide.setAttribute('aria-hidden', String(!active));
                });
                dots.forEach((dot, i) => dot.setAttribute('aria-current', String(i === current)));
                title.textContent = captions[current][0];
                copy.textContent = captions[current][1];
            };

            const stop = () => { if (timer) window.clearInterval(timer); timer = null; };
            const start = () => {
                stop();
                if (!reducedMotion.matches && !document.hidden && slides.length > 1) {
                    timer = window.setInterval(() => showSlide(current + 1), 6000);
                }
            };

            carousel.querySelector('[data-carousel-prev]').addEventListener('click', () => { showSlide(current - 1); start(); });
            carousel.querySelector('[data-carousel-next]').addEventListener('click', () => { showSlide(current + 1); start(); });
            dots.forEach((dot) => dot.addEventListener('click', () => { showSlide(Number(dot.dataset.carouselDot)); start(); }));
            carousel.addEventListener('mouseenter', stop);
            carousel.addEventListener('mouseleave', start);
            carousel.addEventListener('focusin', stop);
            carousel.addEventListener('focusout', (event) => { if (!carousel.contains(event.relatedTarget)) start(); });
            carousel.addEventListener('touchstart', (event) => { touchStartX = event.changedTouches[0].clientX; }, { passive: true });
            carousel.addEventListener('touchend', (event) => {
                if (touchStartX === null) return;
                const delta = event.changedTouches[0].clientX - touchStartX;
                touchStartX = null;
                if (Math.abs(delta) > 45) { showSlide(current + (delta < 0 ? 1 : -1)); start(); }
            }, { passive: true });
            document.addEventListener('visibilitychange', start);
            reducedMotion.addEventListener?.('change', start);
            showSlide(0);
            start();
        })();
    </script>
</body>
</html>

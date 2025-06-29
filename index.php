<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Private Healthcare Portal - Home</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #4e73df;
            --secondary: #858796;
            --light: #f8f9fc;
        }
        
        body {
            background-color: #f8f9fa;
            font-family: 'Nunito', sans-serif;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .hero-section {
            background: linear-gradient(135deg, var(--primary) 0%, #224abe 100%);
            color: white;
            padding: 4rem 0;
            text-align: center;
            flex-grow: 1;
            display: flex;
            align-items: center;
        }
        
        .hero-content {
            max-width: 800px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .hero-title {
            font-size: 2.5rem;
            margin-bottom: 1.5rem;
            font-weight: 700;
        }
        
        .hero-description {
            font-size: 1.2rem;
            margin-bottom: 2.5rem;
            opacity: 0.9;
        }
        
        .auth-container {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            padding: 2rem;
            width: 100%;
            max-width: 500px;
            margin: 2rem auto;
        }
        
        .btn-auth {
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            border-radius: 0.25rem;
            cursor: pointer;
            font-size: 1.1rem;
            width: 100%;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
        }
        
        .btn-login {
            background-color: var(--primary);
            color: white;
            border: none;
        }
        
        .btn-login:hover {
            background-color: #2e59d9;
            color: white;
            transform: translateY(-2px);
        }
        
        .btn-register {
            background-color: #1cc88a;
            color: white;
            border: none;
        }
        
        .btn-register:hover {
            background-color: #17a673;
            transform: translateY(-2px);
        }
        
        .btn-icon {
            margin-right: 0.75rem;
            font-size: 1.2rem;
        }
        
        .features-section {
            padding: 3rem 0;
            background-color: var(--light);
        }
        
        .feature-card {
            background-color: white;
            border-radius: 0.5rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            height: 100%;
            transition: transform 0.3s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-5px);
        }
        
        .feature-icon {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        footer {
            background-color: #2c3e50;
            color: white;
            padding: 1.5rem 0;
            text-align: center;
        }
    </style>
</head>
<body>
    <header>
        <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
            <div class="container">
                <a class="navbar-brand" href="#">
                    <i class="fas fa-heartbeat me-2"></i>Private Healthcare
                </a>
            </div>
        </nav>
    </header>

    <section class="hero-section">
        <div class="hero-content">
            <div>
                <h1 class="hero-title">Private Healthcare Portal</h1>
                <p class="hero-description">
                    Exclusive healthcare management for private clients. Premium services with complete confidentiality.
                </p>
                
                <div class="auth-container">
                    <h3 class="text-center mb-4" style="color: var(--secondary);">Access Your Account</h3>
                    <a href="auth/login.php" class="btn btn-login">
                        <i class="fas fa-sign-in-alt btn-icon"></i> Login
                    </a>
                    <a href="auth/register.php" class="btn btn-register">
                        <i class="fas fa-user-plus btn-icon"></i> Register
                    </a>
                </div>
            </div>
        </div>
    </section>

    <section class="features-section">
        <div class="container">
            <h2 class="text-center mb-5" style="color: var(--primary);">Our Private Services</h2>
            <div class="row justify-content-center">
                <div class="col-md-5">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <h3>Priority Appointments</h3>
                        <p>Exclusive scheduling with your doctor at your preferred times.</p>
                    </div>
                </div>
                <div class="col-md-5">
                    <div class="feature-card">
                        <div class="feature-icon">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3>Discreet Services</h3>
                        <p>Complete confidentiality guaranteed for all your healthcare needs.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer>
        <div class="container">
            <p>&copy; 2053 Private Healthcare. All rights reserved.</p>
            <p class="mb-0">Premium healthcare services for discerning clients.</p>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
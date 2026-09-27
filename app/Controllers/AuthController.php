<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;

class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            header('Location: /');
            exit;
        }
        View::render('auth/login', ['error' => $_GET['error'] ?? null]);
    }

    public function login(): void
    {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            header('Location: /login?error=' . urlencode('Заповніть усі поля'));
            exit;
        }

        if (Auth::attempt($email, $password)) {
            header('Location: /');
            exit;
        }

        header('Location: /login?error=' . urlencode(Auth::lastError() ?? 'Невірний email або пароль'));
        exit;
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /login');
        exit;
    }
}

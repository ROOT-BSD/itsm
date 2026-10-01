<?php

namespace App\Core;

/** Помилка надсилання пошти (з'єднання, автентифікація, відмова сервера прийняти лист). Текст — українською, готовий для показу адміністратору. */
class SmtpException extends \RuntimeException
{
}

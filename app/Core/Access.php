<?php

namespace App\Core;

/**
 * Рольові правила, які мусять діяти ОДНАКОВО в усіх каналах — веб-інтерфейсі, REST API (і вебхуках): інакше API
 * став би «чорним ходом» з іншими правами. Тут лише чисті функції від коду ролі; видимість конкретних записів — у
 * моделях (Project::isVisibleTo, Ticket::isVisibleTo). Нове рольове правило додається тут, а не в окремому контролері.
 */
final class Access
{
    public static function isAdmin(?string $role): bool
    {
        return $role === 'admin';
    }

    /** Бачити в списку ще нікому не призначені тікети (черга, з якої беруть роботу). */
    public static function canSeeUnassignedTickets(?string $role): bool
    {
        return in_array($role, ['it_manager', 'support_operator'], true);
    }

    /** Призначати й знімати оператора тікета. */
    public static function canAssignTicketOperator(?string $role): bool
    {
        return in_array($role, ['admin', 'unit_admin', 'it_manager', 'support_operator'], true);
    }

    /** Чий це коментар у тікеті: оператора чи заявника (так історично визначає й веб-форма). */
    public static function ticketCommentAuthorType(?string $role): string
    {
        return in_array($role, ['admin', 'unit_admin', 'support_operator'], true) ? 'operator' : 'requester';
    }

    /** Створювати проєкти й підпроєкти, призначати відповідального. */
    public static function canManageProjects(?string $role): bool
    {
        return in_array($role, ['admin', 'unit_admin', 'it_manager'], true);
    }

    /** Адміністратор підрозділу: керує користувачами й бачить дані свого AD OU (App\Core\Unit). */
    public static function isUnitAdmin(?string $role): bool
    {
        return $role === Unit::ROLE;
    }
}

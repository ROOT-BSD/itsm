# Гайд Git — ITSM System

*Версія застосунку: 0.1.3*

Проєкт наразі поставляється **без** git-репозиторію (свідомо — щоб не завантажувати зайве на сервер). Ця інструкція — як самостійно ініціалізувати git та відправити код на GitHub (або інший git-хостинг).

---

## 1. Передумови

Перевірте, що git встановлено:
```bash
git --version
```
Якщо немає — `sudo apt install git` (Debian/Ubuntu) або `brew install git` (macOS).

Матимете готовий обліковий запис на GitHub (або GitLab/Gitea) і створений там **порожній** репозиторій (без README, без .gitignore — щоб уникнути конфліктів при першому пуші).

---

## 2. Створення `.gitignore`

У корені проєкту (`itsm-system/`) створіть файл `.gitignore`:

```bash
cat > .gitignore << 'EOF'
# Локальні налаштування середовища — містять пароль БД, унікальні для кожного сервера
.env
.env.new

# Файли, завантажені користувачами через веб-інтерфейс
/storage/uploads/*
!/storage/uploads/.gitkeep

# Системні файли
.DS_Store
Thumbs.db
EOF

# Порожня папка uploads інакше не потрапить у git взагалі
touch storage/uploads/.gitkeep
```

**Важливо:** `.env` міститиме реальний пароль до бази даних — переконайтеся, що він у `.gitignore` **до** першого коміту, інакше пароль назавжди залишиться в історії git навіть після видалення файлу.

---

## 3. Ініціалізація репозиторію та перший коміт

```bash
cd itsm-system

git init
git add .
git status   # перевірте: .env НЕ повинен бути у списку!
git commit -m "Initial commit: ITSM System v$(cat VERSION)"
```

---

## 4. Підключення до GitHub

```bash
git remote add origin https://github.com/ВАШ_ЛОГІН/НАЗВА_РЕПОЗИТОРІЮ.git
git branch -M main
git push -u origin main
```

### Якщо запросить пароль

GitHub більше не приймає звичайний пароль для HTTPS. Два варіанти:

**А) Personal Access Token (простіше для одноразового налаштування)**
1. GitHub → аватар → Settings → Developer settings → Personal access tokens → Tokens (classic) → Generate new token.
2. Права: галочка `repo`. Скопіюйте токен одразу — вдруге він не покажеться.
3. При `git push`, коли запитає пароль — вставте токен (не пароль акаунта).

**Б) SSH-ключ (зручніше, якщо будете пушити часто)**
```bash
ssh-keygen -t ed25519 -C "your_email@example.com"
cat ~/.ssh/id_ed25519.pub
```
Скопіюйте вивід → GitHub → Settings → SSH and GPG keys → New SSH key → вставте.

Потім змініть адресу репозиторію на SSH:
```bash
git remote set-url origin git@github.com:ВАШ_ЛОГІН/НАЗВА_РЕПОЗИТОРІЮ.git
git push -u origin main
```

---

## 5. Подальші оновлення

Після першого пуша кожне наступне надсилання змін:

```bash
git add .
git commit -m "Опис змін"
git push
```

---

## 6. Типові проблеми

**`! [rejected] main -> main (fetch first)`**
На GitHub уже є коміти, яких немає локально (наприклад, GitHub сам додав README при створенні репозиторію). Варіанти:
```bash
# Якщо на GitHub нічого важливого немає — перезаписати:
git push -u origin main --force

# Або об'єднати історії:
git pull --rebase origin main
git push -u origin main
```

**Випадково закомітили `.env` до того, як додали його в `.gitignore`**
Видалення файлу і новий коміт **не** приберуть його з історії. Якщо репозиторій ще ніхто не клонував:
```bash
git rm --cached .env
echo ".env" >> .gitignore
git add .gitignore
git commit -m "Remove .env from repository"
git push --force
```
Якщо репозиторій уже хтось клонував або він публічний — **вважайте пароль скомпрометованим і одразу змініть його в БД**, історію git «підчистити» заднім числом складніше й ненадійно.

**Право на виконання `install.sh`/`update.sh` загубилось після клонування**
```bash
chmod +x install.sh update.sh
git update-index --chmod=+x install.sh update.sh
git commit -m "Restore executable permission"
```

<?php
header('Content-Type: text/html; charset=UTF-8');

// Параметры подключения к БД
$user = 'u82258';
$pass = '7574471';
$dbname = 'u82258';

// Список допустимых языков
$allowedLanguages = [
    'Pascal', 'C', 'C++', 'JavaScript', 'PHP', 'Python',
    'Java', 'Haskel', 'Clojure', 'Prolog', 'Scala', 'Go'
];

function h($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$old = [];

// ---------- ОБРАБОТКА POST (отправка формы) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fio = trim($_POST['fio'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $birth_date = $_POST['birth_date'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $languages = $_POST['languages'] ?? [];
    $biography = trim($_POST['biography'] ?? '');
    $contract = isset($_POST['contract']) ? 1 : 0;

    $old = compact('fio', 'phone', 'email', 'birth_date', 'gender', 'languages', 'biography', 'contract');

    // Валидация (регулярные выражения)
    if (empty($fio)) {
        $errors['fio'] = 'Заполните ФИО.';
    } elseif (strlen($fio) > 150) {
        $errors['fio'] = 'ФИО не должно превышать 150 символов.';
    } elseif (!preg_match('/^[a-zA-Zа-яА-ЯёЁ\s\-]+$/u', $fio)) {
        $errors['fio'] = 'ФИО должно содержать только буквы, пробелы и дефисы.';
    }

    if (empty($phone)) {
        $errors['phone'] = 'Заполните телефон.';
    } elseif (!preg_match('/^[\d\s\(\)\+\-]+$/', $phone)) {
        $errors['phone'] = 'Телефон содержит недопустимые символы.';
    }

    if (empty($email)) {
        $errors['email'] = 'Заполните e-mail.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Некорректный e-mail.';
    }

    if (empty($birth_date)) {
        $errors['birth_date'] = 'Укажите дату рождения.';
    } else {
        $date = DateTime::createFromFormat('Y-m-d', $birth_date);
        if (!$date || $date->format('Y-m-d') !== $birth_date) {
            $errors['birth_date'] = 'Неверный формат даты (используйте ГГГГ-ММ-ДД).';
        } else {
            $today = new DateTime();
            if ($date > $today) {
                $errors['birth_date'] = 'Дата рождения не может быть в будущем.';
            }
        }
    }

    if (!in_array($gender, ['male', 'female'])) {
        $errors['gender'] = 'Выберите пол.';
    }

    if (empty($languages)) {
        $errors['languages'] = 'Выберите хотя бы один язык программирования.';
    } else {
        foreach ($languages as $lang) {
            if (!in_array($lang, $allowedLanguages)) {
                $errors['languages'] = 'Выбран недопустимый язык программирования.';
                break;
            }
        }
    }

    if (strlen($biography) > 500) {
        $errors['biography'] = 'Биография не должна превышать 500 символов.';
    }

    if (!$contract) {
        $errors['contract'] = 'Необходимо ознакомиться с контрактом.';
    }

    // Определяем срок хранения Cookies для значений полей
    $cookieExpire = empty($errors) ? time() + 365 * 24 * 60 * 60 : time() + 30 * 24 * 60 * 60;

    setcookie('value_fio', $fio, $cookieExpire, '/');
    setcookie('value_phone', $phone, $cookieExpire, '/');
    setcookie('value_email', $email, $cookieExpire, '/');
    setcookie('value_birth_date', $birth_date, $cookieExpire, '/');
    setcookie('value_gender', $gender, $cookieExpire, '/');
    setcookie('value_languages', json_encode($languages), $cookieExpire, '/');
    setcookie('value_biography', $biography, $cookieExpire, '/');
    setcookie('value_contract', $contract, $cookieExpire, '/');

    // Если есть ошибки – сохраняем их в Cookies и редиректим

    if (!empty($errors)) {
        foreach (array_keys($errors) as $field) {
            setcookie("error_$field", '1', 0, '/');
        }
        header('Location: ' . $_SERVER['SCRIPT_NAME']);
        exit;
    }

    // --- Нет ошибок: сохраняем в БД ---
    try {
        $pdo = new PDO("mysql:host=localhost;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO applications (fio, phone, email, birth_date, gender, biography, contract_accepted)
            VALUES (:fio, :phone, :email, :birth_date, :gender, :biography, :contract)
        ");
        $stmt->execute([
            ':fio' => $fio,
            ':phone' => $phone,
            ':email' => $email,
            ':birth_date' => $birth_date,
            ':gender' => $gender,
            ':biography' => $biography,
            ':contract' => $contract
        ]);
        $applicationId = $pdo->lastInsertId();

        $stmtLang = $pdo->prepare("
            INSERT INTO application_languages (application_id, language_id)
            VALUES (:app_id, (SELECT id FROM programming_languages WHERE name = :lang_name))
        ");
        foreach ($languages as $lang) {
            $stmtLang->execute([':app_id' => $applicationId, ':lang_name' => $lang]);
        }
        $pdo->commit();

        // Успех: ставим Cookie-сообщение и удаляем все Cookies ошибок
        setcookie('save', '1', time() + 30, '/');
        $errorFields = ['fio', 'phone', 'email', 'birth_date', 'gender', 'languages', 'biography', 'contract'];
        foreach ($errorFields as $field) {
            setcookie("error_$field", '', time() - 3600, '/');
        }
        header('Location: ' . $_SERVER['SCRIPT_NAME']);
        exit;
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errors['db'] = 'Ошибка базы данных: ' . $e->getMessage();
        // При ошибке БД тоже редиректим с общим сообщением
        setcookie('error_db', '1', 0, '/');
        header('Location: ' . $_SERVER['SCRIPT_NAME']);
        exit;
    }
}

// ---------- ОБРАБОТКА GET (показ формы) ----------
$messages = [];
$fieldErrors = [];
$fieldValues = [];

// Читаем Cookie успешного сохранения
if (isset($_COOKIE['save'])) {
    $messages[] = 'Данные успешно сохранены!';
    setcookie('save', '', time() - 3600, '/');
}

// Читаем Cookie ошибок и удаляем их после прочтения
$errorFields = ['fio', 'phone', 'email', 'birth_date', 'gender', 'languages', 'biography', 'contract'];
foreach ($errorFields as $field) {
    if (isset($_COOKIE["error_$field"])) {
        $fieldErrors[$field] = true;
        setcookie("error_$field", '', time() - 3600, '/');
    }
}
if (isset($_COOKIE['error_db'])) {
    $messages[] = 'Ошибка базы данных, попробуйте позже.';
    setcookie('error_db', '', time() - 3600, '/');
}

// Читаем сохранённые значения полей из Cookies
$valueFields = ['fio', 'phone', 'email', 'birth_date', 'gender', 'biography', 'contract'];
foreach ($valueFields as $field) {
    $fieldValues[$field] = $_COOKIE["value_$field"] ?? '';
}
// Языки – отдельно, JSON
$fieldValues['languages'] = [];
if (isset($_COOKIE['value_languages'])) {
    $decoded = json_decode($_COOKIE['value_languages'], true);
    if (is_array($decoded)) {
        $fieldValues['languages'] = $decoded;
    }
}
// Чекбокс contract
$fieldValues['contract'] = isset($fieldValues['contract']) ? (int)$fieldValues['contract'] : 0;

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Анкета</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<div class="container">
    <h1>Форма для регистрации</h1>

    <?php if (!empty($messages)): ?>
        <div class="success"><?= h(implode('<br>', $messages)) ?></div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="field">
            <label for="fio">ФИО *</label>
            <input type="text" id="fio" name="fio"
                   class="<?= isset($fieldErrors['fio']) ? 'error' : '' ?>"
                   value="<?= h($fieldValues['fio']) ?>" required>
            <?php if (isset($fieldErrors['fio'])): ?>
                <span class="error-msg">ФИО обязательно и должно содержать только буквы, пробелы, дефисы (до 150 символов).</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="phone">Телефон *</label>
            <input type="tel" id="phone" name="phone"
                   class="<?= isset($fieldErrors['phone']) ? 'error' : '' ?>"
                   value="<?= h($fieldValues['phone']) ?>" required>
            <?php if (isset($fieldErrors['phone'])): ?>
                <span class="error-msg">Телефон обязателен, допустимы цифры, пробелы, +, -, скобки.</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="email">E-mail *</label>
            <input type="email" id="email" name="email"
                   class="<?= isset($fieldErrors['email']) ? 'error' : '' ?>"
                   value="<?= h($fieldValues['email']) ?>" required>
            <?php if (isset($fieldErrors['email'])): ?>
                <span class="error-msg">Введите корректный e-mail.</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="birth_date">Дата рождения *</label>
            <input type="date" id="birth_date" name="birth_date"
                   class="<?= isset($fieldErrors['birth_date']) ? 'error' : '' ?>"
                   value="<?= h($fieldValues['birth_date']) ?>" required>
            <?php if (isset($fieldErrors['birth_date'])): ?>
                <span class="error-msg">Дата рождения обязательна и не может быть в будущем.</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label>Пол *</label>
            <label><input type="radio" name="gender" value="male"
                <?= ($fieldValues['gender'] === 'male') ? 'checked' : '' ?>
                > Мужской</label>
            <label><input type="radio" name="gender" value="female"
                <?= ($fieldValues['gender'] === 'female') ? 'checked' : '' ?>
                > Женский</label>
            <?php if (isset($fieldErrors['gender'])): ?>
                <span class="error-msg">Выберите пол.</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label>Любимые языки программирования *</label>
            <select name="languages[]" multiple size="6" class="<?= isset($fieldErrors['languages']) ? 'error' : '' ?>">
                <?php foreach ($allowedLanguages as $lang): ?>
                    <option value="<?= h($lang) ?>" <?= in_array($lang, $fieldValues['languages']) ? 'selected' : '' ?>>
                        <?= h($lang) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($fieldErrors['languages'])): ?>
                <span class="error-msg">Выберите хотя бы один язык из списка.</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="biography">Биография</label>
            <textarea id="biography" name="biography" rows="5"
                      class="<?= isset($fieldErrors['biography']) ? 'error' : '' ?>"><?= h($fieldValues['biography']) ?></textarea>
            <?php if (isset($fieldErrors['biography'])): ?>
                <span class="error-msg">Биография не должна превышать 500 символов.</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label><input type="checkbox" name="contract" value="1" <?= $fieldValues['contract'] ? 'checked' : '' ?>> Я ознакомлен с контрактом *</label>
            <?php if (isset($fieldErrors['contract'])): ?>
                <span class="error-msg">Необходимо подтвердить ознакомление с контрактом.</span>
            <?php endif; ?>
        </div>

        <div class="field">
            <button type="submit">Сохранить</button>
        </div>
    </form>
</div>
</body>
</html>

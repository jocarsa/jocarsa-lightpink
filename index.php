<?php
session_start();

// ----------------------------------------------------------------------
// 1. Connect to / Start the SQLite database
// ----------------------------------------------------------------------
$db = new SQLite3('../databases/lightpink.db');

// ----------------------------------------------------------------------
// 2. Create tables if not exist
// ----------------------------------------------------------------------
$db->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        email TEXT NOT NULL UNIQUE,
        username TEXT NOT NULL UNIQUE,
        password TEXT NOT NULL
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS day_slots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        time_slot TEXT NOT NULL,
        description TEXT,
        color_tag TEXT,
        group_flag INTEGER DEFAULT 0,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS daily_data (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        date TEXT NOT NULL,
        notes TEXT,
        checklist TEXT,
        daily_menu TEXT,
        thoughts TEXT,
        sleep_time REAL DEFAULT 0,
        social_time REAL DEFAULT 0,
        me_time REAL DEFAULT 0,
        water_drinked REAL DEFAULT 0,
        meditation_time REAL DEFAULT 0,
        project_time REAL DEFAULT 0,
        exercise_time REAL DEFAULT 0,
        exercise_desc TEXT,
        FOREIGN KEY (user_id) REFERENCES users(id)
    )
");

// ----------------------------------------------------------------------
// 3. Create initial user if not exists
// ----------------------------------------------------------------------
$initialFullName = "Jose Vicente Carratalá";
$initialEmail    = "info@josevicentecarratala.com";
$initialUsername = "jocarsa";
$initialPassword = "jocarsa";

$stmt = $db->prepare("
    INSERT OR IGNORE INTO users (full_name, email, username, password)
    VALUES (:full_name, :email, :username, :password)
");
$stmt->bindValue(':full_name', $initialFullName);
$stmt->bindValue(':email',    $initialEmail);
$stmt->bindValue(':username', $initialUsername);
$stmt->bindValue(':password', $initialPassword);
$stmt->execute();

// ----------------------------------------------------------------------
// 4. Registration
// ----------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'register') {
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $username  = trim($_POST['username']);
    $password  = trim($_POST['password']);

    if ($full_name && $email && $username && $password) {
        $stmt = $db->prepare("
            INSERT INTO users (full_name, email, username, password)
            VALUES (:fn, :em, :us, :pw)
        ");
        $stmt->bindValue(':fn', $full_name);
        $stmt->bindValue(':em', $email);
        $stmt->bindValue(':us', $username);
        $stmt->bindValue(':pw', $password);
        $stmt->execute();
        $message = "Registro exitoso. Por favor, inicie sesión.";
    } else {
        $message = "Por favor, complete todos los campos de registro.";
    }
}

// ----------------------------------------------------------------------
// 5. Login
// ----------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'login') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if ($username && $password) {
        $stmt = $db->prepare("
            SELECT * FROM users 
            WHERE username = :u 
              AND password = :p 
            LIMIT 1
        ");
        $stmt->bindValue(':u', $username);
        $stmt->bindValue(':p', $password);
        $result = $stmt->execute();
        $row = $result->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            $_SESSION['user_id']   = $row['id'];
            $_SESSION['full_name'] = $row['full_name'];
            header("Location: index.php");
            exit;
        } else {
            $message = "Usuario o contraseña incorrectos.";
        }
    } else {
        $message = "Por favor, ingrese usuario y contraseña.";
    }
}

// ----------------------------------------------------------------------
// 6. Logout
// ----------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// ----------------------------------------------------------------------
// 7. AJAX Handling (Save data without reload)
// ----------------------------------------------------------------------
if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['status' => 'error', 'message' => 'No ha iniciado sesión.']);
        exit;
    }
    $user_id = $_SESSION['user_id'];

    // A) Update time slot (day_slots)
    if ($_POST['action'] === 'update_slot') {
        $date       = $_POST['date']       ?? date('Y-m-d');
        $time_slot  = $_POST['time_slot']  ?? '';
        $desc       = trim($_POST['description'] ?? '');
        $color_tag  = $_POST['color_tag']  ?? '';
        $group_flag = isset($_POST['group_flag']) ? (int)$_POST['group_flag'] : 0;

        $check = $db->prepare("
            SELECT id FROM day_slots 
            WHERE user_id = :u 
              AND date = :d 
              AND time_slot = :ts
        ");
        $check->bindValue(':u', $user_id);
        $check->bindValue(':d', $date);
        $check->bindValue(':ts', $time_slot);
        $exists = $check->execute()->fetchArray(SQLITE3_ASSOC);

        if ($exists) {
            // Update
            $upd = $db->prepare("
                UPDATE day_slots
                   SET description = :desc,
                       color_tag   = :color_tag,
                       group_flag  = :group_flag
                 WHERE id = :id
            ");
            $upd->bindValue(':desc',       $desc);
            $upd->bindValue(':color_tag',  $color_tag);
            $upd->bindValue(':group_flag', $group_flag);
            $upd->bindValue(':id',         $exists['id']);
            $upd->execute();
        } else {
            // Insert
            $ins = $db->prepare("
                INSERT INTO day_slots (user_id, date, time_slot, description, color_tag, group_flag)
                VALUES (:u, :d, :ts, :desc, :color_tag, :group_flag)
            ");
            $ins->bindValue(':u',          $user_id);
            $ins->bindValue(':d',          $date);
            $ins->bindValue(':ts',         $time_slot);
            $ins->bindValue(':desc',       $desc);
            $ins->bindValue(':color_tag',  $color_tag);
            $ins->bindValue(':group_flag', $group_flag);
            $ins->execute();
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    // B) Update daily_data
    if ($_POST['action'] === 'update_daily_data') {
        $date  = $_POST['date'] ?? date('Y-m-d');
        $field = $_POST['field'] ?? '';
        $value = trim($_POST['value'] ?? '');

        $allowedFields = [
            'notes', 'checklist', 'daily_menu', 'thoughts',
            'sleep_time', 'social_time', 'me_time', 'water_drinked',
            'meditation_time', 'project_time', 'exercise_time',
            'exercise_desc'
        ];
        if (!in_array($field, $allowedFields)) {
            echo json_encode(['status' => 'error', 'message' => 'Campo no permitido.']);
            exit;
        }

        $check = $db->prepare("
            SELECT id FROM daily_data 
            WHERE user_id = :u 
              AND date = :d
        ");
        $check->bindValue(':u', $user_id);
        $check->bindValue(':d', $date);
        $row = $check->execute()->fetchArray(SQLITE3_ASSOC);

        if ($row) {
            // Update
            $upd = $db->prepare("
                UPDATE daily_data
                   SET $field = :val
                 WHERE id = :id
            ");
            $upd->bindValue(':val', $value);
            $upd->bindValue(':id', $row['id']);
            $upd->execute();
        } else {
            // Insert with default values and the updated field
            $insFields = [
                'notes'            => '',
                'checklist'        => '',
                'daily_menu'       => '',
                'thoughts'         => '',
                'sleep_time'       => 0,
                'social_time'      => 0,
                'me_time'          => 0,
                'water_drinked'    => 0,
                'meditation_time'  => 0,
                'project_time'     => 0,
                'exercise_time'    => 0,
                'exercise_desc'    => '',
            ];
            $insFields[$field] = $value;

            $ins = $db->prepare("
                INSERT INTO daily_data
                (user_id, date, notes, checklist, daily_menu, thoughts,
                 sleep_time, social_time, me_time, water_drinked, meditation_time,
                 project_time, exercise_time, exercise_desc)
                VALUES 
                (:u, :d, :notes, :checklist, :daily_menu, :thoughts,
                 :sleep_time, :social_time, :me_time, :water_drinked,
                 :meditation_time, :project_time, :exercise_time, :exercise_desc)
            ");
            $ins->bindValue(':u', $user_id);
            $ins->bindValue(':d', $date);
            $ins->bindValue(':notes',           $insFields['notes']);
            $ins->bindValue(':checklist',       $insFields['checklist']);
            $ins->bindValue(':daily_menu',      $insFields['daily_menu']);
            $ins->bindValue(':thoughts',        $insFields['thoughts']);
            $ins->bindValue(':sleep_time',      $insFields['sleep_time']);
            $ins->bindValue(':social_time',     $insFields['social_time']);
            $ins->bindValue(':me_time',         $insFields['me_time']);
            $ins->bindValue(':water_drinked',   $insFields['water_drinked']);
            $ins->bindValue(':meditation_time', $insFields['meditation_time']);
            $ins->bindValue(':project_time',    $insFields['project_time']);
            $ins->bindValue(':exercise_time',   $insFields['exercise_time']);
            $ins->bindValue(':exercise_desc',   $insFields['exercise_desc']);
            $ins->execute();
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    // Invalid action
    echo json_encode(['status' => 'error', 'message' => 'Acción no válida.']);
    exit;
}

// ----------------------------------------------------------------------
// 8. Determine view: 'day', 'week', or 'month'
// ----------------------------------------------------------------------
$view = isset($_GET['view']) ? $_GET['view'] : 'day';

// ----------------------------------------------------------------------
// 9. Logic for view rendering
// ----------------------------------------------------------------------
if ($view === 'day') {
    $currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    // Navigation: previous/next
    if (isset($_GET['prev']) && $_GET['prev'] == 1) {
        $time = strtotime($currentDate) - 86400;
        $currentDate = date('Y-m-d', $time);
    }
    if (isset($_GET['next']) && $_GET['next'] == 1) {
        $time = strtotime($currentDate) + 86400;
        $currentDate = date('Y-m-d', $time);
    }

    // Retrieve daily_data and day_slots for the current day
    $dailyData   = [];
    $timeSlots   = [];
    $daySlotData = [];

    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];

        // a) daily_data query
        $stmt = $db->prepare("
            SELECT * FROM daily_data 
            WHERE user_id = :u 
              AND date = :d
        ");
        $stmt->bindValue(':u', $user_id);
        $stmt->bindValue(':d', $currentDate);
        $res = $stmt->execute();
        $dailyData = $res->fetchArray(SQLITE3_ASSOC);

        // b) Generate slots from 06:00 to 24:00 in 30-minute intervals
        $start = strtotime("06:00");
        $end   = strtotime("24:00");
        for ($t = $start; $t < $end; $t += 1800) {
            $timeSlots[] = date('H:i', $t);
        }

        // c) Retrieve existing day_slots
        $slotStmt = $db->prepare("
            SELECT time_slot, description, color_tag, group_flag
            FROM day_slots
            WHERE user_id = :u 
              AND date = :d
        ");
        $slotStmt->bindValue(':u', $user_id);
        $slotStmt->bindValue(':d', $currentDate);
        $slotRes = $slotStmt->execute();
        while ($row = $slotRes->fetchArray(SQLITE3_ASSOC)) {
            $ts = $row['time_slot'];
            $daySlotData[$ts] = [
                'description' => $row['description'],
                'color_tag'   => $row['color_tag'],
                'group_flag'  => $row['group_flag']
            ];
        }
    }
}

if ($view === 'month') {
    $year  = isset($_GET['year'])  ? (int)$_GET['year']  : date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

    if (isset($_GET['prev']) && $_GET['prev'] == 1) {
        $prevMonth = strtotime("-1 month", strtotime("$year-$month-01"));
        $year  = date('Y', $prevMonth);
        $month = date('n', $prevMonth);
    }
    if (isset($_GET['next']) && $_GET['next'] == 1) {
        $nextMonth = strtotime("+1 month", strtotime("$year-$month-01"));
        $year  = date('Y', $nextMonth);
        $month = date('n', $nextMonth);
    }

    $firstDayOfMonth = strtotime("$year-$month-01");
    $daysInMonth     = date('t', $firstDayOfMonth);
    $monthStart      = date('Y-m-d', $firstDayOfMonth);
    $monthEnd        = date('Y-m-d', strtotime("$year-$month-$daysInMonth"));
    $monthEvents     = [];

    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $slotStmt = $db->prepare("
             SELECT date, time_slot, description
             FROM day_slots
             WHERE user_id = :u
             AND date >= :start
             AND date <= :end
             ORDER BY date, time_slot
        ");
        $slotStmt->bindValue(':u', $user_id);
        $slotStmt->bindValue(':start', $monthStart);
        $slotStmt->bindValue(':end',   $monthEnd);
        $slotRes = $slotStmt->execute();
        while ($row = $slotRes->fetchArray(SQLITE3_ASSOC)) {
             if (!empty(trim($row['description']))) {
                  $day = $row['date'];
                  if (!isset($monthEvents[$day])) {
                       $monthEvents[$day] = [];
                  }
                  $monthEvents[$day][] = [
                       'time_slot'   => $row['time_slot'],
                       'description' => $row['description']
                  ];
             }
        }
    }
}

if ($view === 'week') {
    // Use the provided date or current date as reference.
    $currentDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
    
    // Compute week boundaries (assuming week starts on Sunday)
    $timestamp = strtotime($currentDate);
    $weekday   = date('w', $timestamp);
    $weekStartTimestamp = strtotime("-{$weekday} days", $timestamp);
    $weekEndTimestamp   = strtotime("+".(6 - $weekday)." days", $timestamp);
    $weekStart = date('Y-m-d', $weekStartTimestamp);
    $weekEnd   = date('Y-m-d', $weekEndTimestamp);

    // Navigation for week view
    if (isset($_GET['prev']) && $_GET['prev'] == 1) {
         // Move one week back.
         $newDate = date('Y-m-d', strtotime("$weekStart -7 days"));
         $currentDate = $newDate;
         $timestamp = strtotime($currentDate);
         $weekday   = date('w', $timestamp);
         $weekStartTimestamp = strtotime("-{$weekday} days", $timestamp);
         $weekEndTimestamp   = strtotime("+".(6 - $weekday)." days", $timestamp);
         $weekStart = date('Y-m-d', $weekStartTimestamp);
         $weekEnd   = date('Y-m-d', $weekEndTimestamp);
    }
    if (isset($_GET['next']) && $_GET['next'] == 1) {
         // Move one week forward.
         $newDate = date('Y-m-d', strtotime("$weekEnd +1 day"));
         $currentDate = $newDate;
         $timestamp = strtotime($currentDate);
         $weekday   = date('w', $timestamp);
         $weekStartTimestamp = strtotime("-{$weekday} days", $timestamp);
         $weekEndTimestamp   = strtotime("+".(6 - $weekday)." days", $timestamp);
         $weekStart = date('Y-m-d', $weekStartTimestamp);
         $weekEnd   = date('Y-m-d', $weekEndTimestamp);
    }

    // Retrieve events for the week
    $weeklyEvents = [];
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $stmt = $db->prepare("
            SELECT * FROM day_slots 
             WHERE user_id = :u 
               AND date >= :start
               AND date <= :end
             ORDER BY date, time_slot
        ");
        $stmt->bindValue(':u', $user_id);
        $stmt->bindValue(':start', $weekStart);
        $stmt->bindValue(':end', $weekEnd);
        $result = $stmt->execute();
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $date = $row['date'];
            $time = $row['time_slot'];
            if (!isset($weeklyEvents[$date])) {
                $weeklyEvents[$date] = [];
            }
            $weeklyEvents[$date][$time] = [
                'description' => $row['description'],
                'color_tag'   => $row['color_tag'],
                'group_flag'  => $row['group_flag']
            ];
        }
    }

    // Generate hourly slots for the weekly view (00:00 to 23:00)
    $timeSlots = [];
    for ($h = 0; $h < 24; $h++) {
         $timeSlots[] = sprintf('%02d:00', $h);
    }
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8"/>
    <!-- Mobile responsiveness -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>jocarsa | lightpink</title>
    <link rel="stylesheet" href="style.css">
    <link rel="icon" href="lightpink.png" type="image/x-icon">
    <link rel="icon" type="image/png" sizes="32x32" href="lightpink.png">
    <link rel="icon" type="image/png" sizes="16x16" href="lightpink.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/lightpink.png">
    <meta name="theme-color" content="#ffffff">
</head>
<body>

<header class="top-header">
    <!-- Corporate Identity -->
    <div class="header-title" title="Tu agenda diaria en línea">
        <h1>
            <img src="lightpink.png" alt="logo">jocarsa | lightpink
        </h1>
    </div>

    <!-- If logged in, show user info and navigation -->
    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="header-userinfo">
            <!-- User Name -->
            <div class="welcome" title="Nombre del usuario conectado">
                <?php echo htmlspecialchars($_SESSION['full_name']); ?>
            </div>
            <!-- Navigation for day view -->
            <?php if ($view === 'day'): ?>
                <nav class="date-nav">
                    <a href="?view=day&prev=1&date=<?php echo $currentDate; ?>" title="Día anterior">&lt;</a>
                    <span><?php echo $currentDate; ?></span>
                    <a href="?view=day&next=1&date=<?php echo $currentDate; ?>" title="Día siguiente">&gt;</a>
                </nav>
            <?php endif; ?>

            <!-- Additional Links: toggle between views and logout -->
            <div class="header-links">
                <?php if ($view === 'day'): ?>
                    <a href="?view=month" title="Ver vista mensual">&#128197; Mes</a>
                    <a href="?view=week" title="Ver vista semanal">&#128337; Semana</a>
                <?php elseif ($view === 'month'): ?>
                    <a href="?view=day" title="Ver vista diaria">&#128337; Día</a>
                    <a href="?view=week" title="Ver vista semanal">&#128337; Semana</a>
                <?php elseif ($view === 'week'): ?>
                    <a href="?view=day" title="Ver vista diaria">&#128337; Día</a>
                    <a href="?view=month" title="Ver vista mensual">&#128197; Mes</a>
                <?php endif; ?>
                <a class="logout-link" href="index.php?action=logout" title="Cerrar Sesión" onclick="localStorage.removeItem('rememberedUser'); localStorage.removeItem('rememberedPassword');">Salir</a>
            </div>
        </div>
    <?php endif; ?>
</header>

<?php if (isset($message)): ?>
    <div class="message">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

<?php if (!isset($_SESSION['user_id'])): ?>
    <!-- Login & Registration Forms -->
    <div class="auth-container">
        <div class="login-form">
            <h2>Iniciar Sesión</h2>
            <form method="POST">
                <input type="hidden" name="action" value="login">
                <label>Usuario</label>
                <input type="text" name="username" required>
                <label>Contraseña</label>
                <input type="password" name="password" required>
                <button type="submit">Entrar</button>
            </form>
        </div>

        <div class="register-form">
            <h2>Registrarse</h2>
            <form method="POST">
                <input type="hidden" name="action" value="register">
                <label>Nombre Completo</label>
                <input type="text" name="full_name" required>
                <label>Email</label>
                <input type="email" name="email" required>
                <label>Usuario</label>
                <input type="text" name="username" required>
                <label>Contraseña</label>
                <input type="password" name="password" required>
                <button type="submit">Crear Cuenta</button>
            </form>
        </div>
    </div>

    <!-- Auto-login using localStorage -->
    <script>
    (function(){
        var storedUser = localStorage.getItem('rememberedUser');
        var storedPass = localStorage.getItem('rememberedPassword');
        if (storedUser && storedPass) {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = 'index.php';
            
            var actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'login';
            form.appendChild(actionInput);
            
            var usernameInput = document.createElement('input');
            usernameInput.type = 'hidden';
            usernameInput.name = 'username';
            usernameInput.value = storedUser;
            form.appendChild(usernameInput);
            
            var passwordInput = document.createElement('input');
            passwordInput.type = 'hidden';
            passwordInput.name = 'password';
            passwordInput.value = storedPass;
            form.appendChild(passwordInput);
            
            document.body.appendChild(form);
            form.submit();
        }
    })();

    (function(){
        var loginForm = document.querySelector('.login-form form');
        if (loginForm) {
            loginForm.addEventListener('submit', function(){
                var userInput = loginForm.querySelector('input[name="username"]').value;
                var passInput = loginForm.querySelector('input[name="password"]').value;
                localStorage.setItem('rememberedUser', userInput);
                localStorage.setItem('rememberedPassword', passInput);
            });
        }
    })();
    </script>

<?php else: ?>

    <!-- Monthly View -->
    <?php if ($view === 'month'): ?>
        <?php
        $firstDayOfMonth  = $firstDayOfMonth ?? strtotime(date('Y-m-01'));
        $daysInMonth      = $daysInMonth ?? date('t');
        $startWeekDay     = date('w', $firstDayOfMonth);
        setlocale(LC_TIME, 'es_ES.UTF-8');
        $monthName        = strftime('%B', $firstDayOfMonth);
        ?>
        <div class="month-desktop" style="padding:20px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <a style="text-decoration:none; font-size:1.2rem;" href="?view=month&prev=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&lt;&lt;</a>
                <div style="font-weight:bold; font-size:1.1rem;">
                    <?php echo ucfirst($monthName) . " " . $year; ?>
                </div>
                <a style="text-decoration:none; font-size:1.2rem;" href="?view=month&next=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&gt;&gt;</a>
            </div>
            <table class="month-table">
                <thead>
                    <tr>
                        <th>Dom</th>
                        <th>Lun</th>
                        <th>Mar</th>
                        <th>Mié</th>
                        <th>Jue</th>
                        <th>Vie</th>
                        <th>Sáb</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $dayCounter = 1; 
                $cellCount  = 0;
                echo "<tr>";
                for ($blank = 0; $blank < $startWeekDay; $blank++) {
                    echo "<td></td>";
                    $cellCount++;
                }
                while ($dayCounter <= $daysInMonth) {
                    if ($cellCount % 7 == 0 && $cellCount != 0) {
                        echo "</tr><tr>";
                    }
                    $currentCellDate = sprintf('%04d-%02d-%02d', $year, $month, $dayCounter);
                    $events = isset($monthEvents[$currentCellDate]) ? $monthEvents[$currentCellDate] : [];
                    echo "<td>";
                    echo "<div style='font-weight:bold; margin-bottom:4px;'>
                            <a href='?view=day&date=$currentCellDate' style='text-decoration:none; color:#862D42;'>$dayCounter</a>
                          </div>";
                    foreach ($events as $ev) {
                        $t = htmlspecialchars($ev['time_slot']);
                        $d = htmlspecialchars($ev['description']);
                        echo "<div style='margin-bottom:4px; font-size:0.85rem;'>
                                <a href='?view=day&date=$currentCellDate' style='color:#862D42; text-decoration:none;'>$t - $d</a>
                              </div>";
                    }
                    echo "</td>";
                    $dayCounter++;
                    $cellCount++;
                }
                while ($cellCount % 7 != 0) {
                    echo "<td></td>";
                    $cellCount++;
                }
                echo "</tr>";
                ?>
                </tbody>
            </table>
        </div>
        
        <div class="month-mobile" style="padding:20px;">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                <a style="text-decoration:none; font-size:1.2rem;" href="?view=month&prev=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&lt;&lt;</a>
                <div style="font-weight:bold; font-size:1.1rem;"><?php echo ucfirst($monthName) . " " . $year; ?></div>
                <a style="text-decoration:none; font-size:1.2rem;" href="?view=month&next=1&year=<?php echo $year; ?>&month=<?php echo $month; ?>">&gt;&gt;</a>
            </div>
            <?php
            for ($day = 1; $day <= $daysInMonth; $day++) {
                $currentCellDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                echo '<div class="month-mobile-day" style="border:1px solid #FFD9E1; padding: 10px; margin-bottom: 5px;">';
                echo '<div class="month-mobile-date" style="font-weight:bold; margin-bottom:5px;">';
                echo "<a href='?view=day&date=$currentCellDate' style='text-decoration:none; color:#862D42;'>$day</a>";
                echo '</div>';
                if (isset($monthEvents[$currentCellDate])) {
                    foreach ($monthEvents[$currentCellDate] as $ev) {
                        $t = htmlspecialchars($ev['time_slot']);
                        $d = htmlspecialchars($ev['description']);
                        echo "<div style='font-size:0.85rem; margin-bottom:4px;'>
                                <a href='?view=day&date=$currentCellDate' style='color:#862D42; text-decoration:none;'>$t - $d</a>
                              </div>";
                    }
                }
                echo '</div>';
            }
            ?>
        </div>
    
    <!-- Weekly View -->
    <?php elseif ($view === 'week'): ?>
        <div class="week-nav">
            <a href="?view=week&prev=1&date=<?php echo $weekStart; ?>" title="Semana Anterior">&lt;&lt;</a>
            <span><?php echo "$weekStart a $weekEnd"; ?></span>
            <a href="?view=week&next=1&date=<?php echo $weekEnd; ?>" title="Semana Siguiente">&gt;&gt;</a>
        </div>
        <div class="week-view-container">
            <?php 
            // Loop through each day of the week.
            $dateIterator = $weekStart;
            for ($d = 0; $d < 7; $d++):
                $dayTimestamp = strtotime($dateIterator);
                $dayName = strftime('%A', $dayTimestamp); 
            ?>
                <div class="week-day-column">
                    <div class="week-day-header">
                        <span class="day-name"><?php echo ucfirst($dayName); ?></span>
                        <span class="day-date"><?php echo $dateIterator; ?></span>
                    </div>
                    <div class="week-time-slots">
                        <?php 
                        foreach ($timeSlots as $slot):
                            $event = isset($weeklyEvents[$dateIterator][$slot]) ? 
                                     $weeklyEvents[$dateIterator][$slot] : 
                                     ['description'=>'', 'color_tag'=>'#ffffff', 'group_flag'=>0];
                        ?>
                        <div class="week-slot-row" data-date="<?php echo $dateIterator; ?>" data-timeslot="<?php echo $slot; ?>">
                            <div class="slot-time"><?php echo $slot; ?></div>
                            <input type="color" class="slot-color-input" value="<?php echo htmlspecialchars($event['color_tag']); ?>">
                            <input type="checkbox" class="slot-group-input" <?php echo $event['group_flag'] ? 'checked' : ''; ?>>
                            <textarea class="slot-input"><?php echo htmlspecialchars($event['description']); ?></textarea>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php 
                $dateIterator = date('Y-m-d', strtotime("$dateIterator +1 day"));
            endfor; 
            ?>
        </div>
        <script>
        (function(){
            document.querySelectorAll('.week-slot-row').forEach(function(row) {
                row.querySelector('.slot-color-input').addEventListener('input', function(){
                    row.style.backgroundColor = this.value;
                    saveWeeklySlot(row);
                });
                row.querySelector('.slot-group-input').addEventListener('change', function(){
                    saveWeeklySlot(row);
                });
                row.querySelector('.slot-input').addEventListener('input', function(){
                    saveWeeklySlot(row);
                });
            });
            function saveWeeklySlot(row) {
                const date = row.getAttribute('data-date');
                const timeSlot = row.getAttribute('data-timeslot');
                const description = row.querySelector('.slot-input').value;
                const colorTag = row.querySelector('.slot-color-input').value;
                const groupFlag = row.querySelector('.slot-group-input').checked ? 1 : 0;
                
                fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        ajax: '1',
                        action: 'update_slot',
                        date: date,
                        time_slot: timeSlot,
                        description: description,
                        color_tag: colorTag,
                        group_flag: groupFlag
                    })
                })
                .then(response => response.json())
                .then(resp => {
                    if (resp.status !== 'ok') {
                        console.error("Error al guardar el slot:", resp);
                    }
                })
                .catch(err => console.error(err));
            }
        })();
        </script>
    
    <!-- Day View -->
    <?php else: ?>
        <div class="agenda-container">
            <div class="left-panel">
                <table class="time-table" title="Tabla de horarios (cada 30 minutos)">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Color</th>
                            <th>Group</th>
                            <th>Contenido</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($timeSlots as $slot):
                        $desc      = $daySlotData[$slot]['description'] ?? '';
                        $colorTag  = $daySlotData[$slot]['color_tag']   ?? '#ffffff';
                        $groupFlag = $daySlotData[$slot]['group_flag']  ?? 0;
                        $checked   = $groupFlag ? 'checked' : '';
                    ?>
                        <tr class="time-slot-row" data-timeslot="<?php echo $slot; ?>">
                            <td class="slot-label"><?php echo $slot; ?></td>
                            <td class="slot-color">
                                <input type="color" class="slot-color-input" data-timeslot="<?php echo $slot; ?>" value="<?php echo htmlspecialchars($colorTag); ?>">
                            </td>
                            <td class="slot-group">
                                <input type="checkbox" class="slot-group-input" data-timeslot="<?php echo $slot; ?>" <?php echo $checked; ?>>
                            </td>
                            <td>
                                <textarea class="slot-input" data-timeslot="<?php echo $slot; ?>" title="Notas para la hora <?php echo $slot; ?>"><?php echo htmlspecialchars($desc); ?></textarea>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="right-panel">
                <?php
                $notes           = $dailyData['notes']            ?? '';
                $checklist       = $dailyData['checklist']        ?? '';
                $daily_menu      = $dailyData['daily_menu']       ?? '';
                $thoughts        = $dailyData['thoughts']         ?? '';
                $sleep_time      = $dailyData['sleep_time']       ?? 0;
                $social_time     = $dailyData['social_time']      ?? 0;
                $me_time         = $dailyData['me_time']          ?? 0;
                $water_drinked   = $dailyData['water_drinked']    ?? 0;
                $meditation_time = $dailyData['meditation_time']  ?? 0;
                $project_time    = $dailyData['project_time']     ?? 0;
                $exercise_time   = $dailyData['exercise_time']    ?? 0;
                $exercise_desc   = $dailyData['exercise_desc']    ?? '';
                ?>
                <h2>Información Diaria</h2>
                <label>Notas</label>
                <textarea class="daily-data" data-field="notes"><?php echo htmlspecialchars($notes); ?></textarea>
                <label>Lista de Tareas</label>
                <textarea class="daily-data" data-field="checklist"><?php echo htmlspecialchars($checklist); ?></textarea>
                <label>Menú Diario</label>
                <textarea class="daily-data" data-field="daily_menu"><?php echo htmlspecialchars($daily_menu); ?></textarea>
                <label>Reflexiones / Saludos</label>
                <textarea class="daily-data" data-field="thoughts"><?php echo htmlspecialchars($thoughts); ?></textarea>
                <div class="hours-grid">
                    <div>
                        <label>😴 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="sleep_time" value="<?php echo $sleep_time; ?>">
                    </div>
                    <div>
                        <label>💚 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="social_time" value="<?php echo $social_time; ?>">
                    </div>
                    <div>
                        <label>😊 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="me_time" value="<?php echo $me_time; ?>">
                    </div>
                    <div>
                        <label>💧 (L)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="water_drinked" value="<?php echo $water_drinked; ?>">
                    </div>
                    <div>
                        <label>🌞 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="meditation_time" value="<?php echo $meditation_time; ?>">
                    </div>
                    <div>
                        <label>🚀 (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="project_time" value="<?php echo $project_time; ?>">
                    </div>
                </div>
                <div class="exercise-row">
                    <div>
                        <label>Tiempo de Ejercicio (h)</label>
                        <input type="number" step="0.5" class="daily-data" data-field="exercise_time" value="<?php echo $exercise_time; ?>">
                    </div>
                    <div>
                        <label>Descripción de Ejercicio</label>
                        <input type="text" class="daily-data" data-field="exercise_desc" value="<?php echo htmlspecialchars($exercise_desc); ?>" placeholder="Ej: Correr, Yoga, Pesas...">
                    </div>
                </div>
            </div>
        </div>
        <script>
        (function(){
            const currentDate = "<?php echo $currentDate; ?>";
            document.querySelectorAll('.time-slot-row').forEach(row => {
                row.querySelector('.slot-color-input').addEventListener('input', function(){
                    const rowElem = this.closest('tr.time-slot-row');
                    rowElem.style.backgroundColor = this.value;
                    saveSlotFromRow(rowElem);
                });
                row.querySelector('.slot-group-input').addEventListener('change', function(){
                    saveSlotFromRow(this.closest('tr.time-slot-row'));
                });
                row.querySelector('.slot-input').addEventListener('input', function(){
                    saveSlotFromRow(this.closest('tr.time-slot-row'));
                });
            });
            function saveSlotFromRow(row) {
                const timeSlot = row.getAttribute('data-timeslot');
                const description = row.querySelector('.slot-input').value;
                const colorTag = row.querySelector('.slot-color-input').value;
                const groupFlag = row.querySelector('.slot-group-input').checked ? 1 : 0;
                
                fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        ajax: '1',
                        action: 'update_slot',
                        date: currentDate,
                        time_slot: timeSlot,
                        description: description,
                        color_tag: colorTag,
                        group_flag: groupFlag
                    })
                })
                .then(r => r.json())
                .then(resp => {
                    if (resp.status !== 'ok') {
                        console.error("Error guardando slot:", resp);
                    }
                })
                .catch(err => console.error(err));
            }
            document.querySelectorAll('.daily-data').forEach(function(elem){
                elem.addEventListener('input', function(){
                    let field = this.getAttribute('data-field');
                    let value = this.value;
                    saveDailyData(field, value);
                });
            });
            function saveDailyData(field, value) {
                fetch('index.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        ajax: '1',
                        action: 'update_daily_data',
                        date: currentDate,
                        field: field,
                        value: value
                    })
                })
                .then(r => r.json())
                .then(resp => {
                    if (resp.status !== 'ok') {
                        console.error("Error guardando daily data:", resp);
                    }
                })
                .catch(err => console.error(err));
            }
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>

</body>
</html>


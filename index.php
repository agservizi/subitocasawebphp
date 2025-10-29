<?php
/*
 * Configurare $admin_email con l'indirizzo email che riceverà le notifiche.
 * Assicurarsi che le cartelle "uploads/" e "data/" siano scrivibili dal web server (es. permessi 755 su Linux o scrittura concessa all'utente IIS su Windows).
 * In ambienti di produzione preferire librerie come PHPMailer o servizi dedicati per invii affidabili e gestione allegati.
 * Possibili miglioramenti: integrare reCAPTCHA per ridurre lo spam, salvare i dati su database (es. MySQL) anziché CSV, usare storage esterno (S3 o upload presignati), aggiungere header di sicurezza e rate limiting.
 * Compatibile con PHP 7+.
 */

declare(strict_types=1);

session_start();
mb_internal_encoding('UTF-8');
date_default_timezone_set('Europe/Rome');

$admin_email = 'info@example.com';

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
$uploadDirRelative = 'uploads';
$dataDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
$csvPath = $dataDir . DIRECTORY_SEPARATOR . 'submissions.csv';
$logPath = $dataDir . DIRECTORY_SEPARATOR . 'errors.log';

$maxFiles = 5;
$maxFileSize = 5 * 1024 * 1024; // 5 MB
$allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
$allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
$blockedExtensions = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'shtml', 'asp', 'aspx', 'jsp', 'exe', 'js', 'sh', 'bat', 'cmd', 'com', 'csh', 'pl', 'cgi'];
$operationOptions = [
    'vendita' => 'Vendita',
    'affitto' => 'Affitto',
    'altro' => 'Altro',
];
$propertyOptions = [
    'appartamento' => 'Appartamento',
    'casa_indipendente' => 'Casa indipendente',
    'commerciale' => 'Commerciale',
    'terreno' => 'Terreno',
    'altro' => 'Altro',
];

$generalErrors = [];
$fieldErrors = [];
$generalWarnings = [];
$uploadedFiles = [];
$fileQueue = [];
$submissionSaved = false;
$successMessage = '';
$displayUploads = [];
$privacyAccepted = false;

$formData = [
    'nome' => '',
    'cognome' => '',
    'azienda' => '',
    'telefono' => '',
    'email' => '',
    'indirizzo' => '',
    'cap' => '',
    'citta' => '',
    'provincia' => '',
    'operazione' => 'vendita',
    'tipologia' => 'appartamento',
    'locali' => '',
    'mq' => '',
    'prezzo' => '',
    'descrizione' => '',
];

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Scrive un messaggio di log interno.
 */
function logInternalError(string $message, string $logPath): void
{
    $dir = dirname($logPath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $entry = '[' . date('c') . '] ' . $message . PHP_EOL;
    @file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
}

/**
 * Garantisce che la cartella esista ed è scrivibile.
 */
function ensureDirectoryWritable(string $path, string $logPath): bool
{
    if (!is_dir($path)) {
        if (!@mkdir($path, 0755, true)) {
            logInternalError('Impossibile creare la cartella: ' . $path, $logPath);
            return false;
        }
    }
    if (!is_writable($path)) {
        logInternalError('Cartella non scrivibile: ' . $path, $logPath);
        return false;
    }
    return true;
}

function sanitizeInput(?string $value): string
{
    return trim($value ?? '');
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$dataDirAccessible = ensureDirectoryWritable($dataDir, $logPath);
$uploadDirAccessible = ensureDirectoryWritable($uploadDir, $logPath);

if (!$dataDirAccessible) {
    $generalErrors[] = 'La cartella "data/" non è accessibile: verificare i permessi di scrittura.';
}
if (!$uploadDirAccessible) {
    $generalErrors[] = 'La cartella "uploads/" non è accessibile: verificare i permessi di scrittura.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($formData as $key => $value) {
        $formData[$key] = sanitizeInput($_POST[$key] ?? '');
    }
    $privacyAccepted = isset($_POST['privacy']);

    $postedToken = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if ($sessionToken === '' || !hash_equals($sessionToken, $postedToken)) {
        $generalErrors[] = 'Token CSRF non valido. Aggiorna la pagina e riprova.';
    }

    if ($formData['nome'] === '' && $formData['azienda'] === '') {
        $fieldErrors['nome'] = 'Inserisci il nome oppure il nome dell\'azienda.';
        $fieldErrors['azienda'] = 'Inserisci il nome oppure il nome dell\'azienda.';
    }

    if ($formData['email'] === '') {
        $fieldErrors['email'] = 'Inserisci un indirizzo email valido.';
    } elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = 'Inserisci un indirizzo email valido.';
    }

    if ($formData['operazione'] === '' || !array_key_exists($formData['operazione'], $operationOptions)) {
        $fieldErrors['operazione'] = 'Seleziona un tipo di operazione valido.';
    }

    if ($formData['tipologia'] === '' || !array_key_exists($formData['tipologia'], $propertyOptions)) {
        $fieldErrors['tipologia'] = 'Seleziona una tipologia di immobile valida.';
    }

    if ($formData['locali'] !== '') {
        if (!ctype_digit($formData['locali']) || (int)$formData['locali'] <= 0) {
            $fieldErrors['locali'] = 'Inserisci un numero di locali valido (solo numeri interi positivi).';
        }
    }

    if ($formData['mq'] !== '') {
        $normalizedMq = str_replace(',', '.', $formData['mq']);
        if (!is_numeric($normalizedMq) || (float)$normalizedMq <= 0) {
            $fieldErrors['mq'] = 'Inserisci una metratura valida (numeri positivi).';
        } else {
            $formData['mq'] = $normalizedMq;
        }
    }

    if ($formData['prezzo'] !== '') {
        $normalizedPrice = str_replace(',', '.', $formData['prezzo']);
        if (!is_numeric($normalizedPrice) || (float)$normalizedPrice < 0) {
            $fieldErrors['prezzo'] = 'Inserisci un prezzo valido (numeri positivi o lascia vuoto).';
        } else {
            $formData['prezzo'] = $normalizedPrice;
        }
    }

    if (!$privacyAccepted) {
        $fieldErrors['privacy'] = 'Devi accettare la privacy.';
    }

    if (!empty($_FILES['allegati']) && is_array($_FILES['allegati']['name'])) {
        $fileNames = $_FILES['allegati']['name'];
        $fileTmpNames = $_FILES['allegati']['tmp_name'];
        $fileSizes = $_FILES['allegati']['size'];
        $fileErrors = $_FILES['allegati']['error'];
        $nonEmptyCount = 0;

        foreach ($fileNames as $idx => $originalName) {
            if ($originalName === '' || $fileTmpNames[$idx] === '') {
                continue;
            }
            $nonEmptyCount++;
        }

        if ($nonEmptyCount > $maxFiles) {
            $generalErrors[] = 'Puoi caricare al massimo ' . $maxFiles . ' file.';
        }

        foreach ($fileNames as $idx => $originalName) {
            if ($originalName === '' || $fileTmpNames[$idx] === '') {
                continue;
            }

            $tmpName = $fileTmpNames[$idx];
            $size = (int)$fileSizes[$idx];
            $errorCode = (int)$fileErrors[$idx];
            $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

            if ($errorCode !== UPLOAD_ERR_OK) {
                $message = 'Errore nel caricamento del file "' . escape($originalName) . '" (codice ' . $errorCode . ').';
                $fieldErrors['allegati'][] = $message;
                logInternalError('Upload error code ' . $errorCode . ' per file: ' . $originalName, $logPath);
                continue;
            }

            if ($size > $maxFileSize) {
                $fieldErrors['allegati'][] = 'File troppo grande: "' . escape($originalName) . '" (max 5 MB).';
                continue;
            }

            if (!in_array($extension, $allowedExtensions, true) || in_array($extension, $blockedExtensions, true)) {
                $fieldErrors['allegati'][] = 'Estensione non consentita per il file "' . escape($originalName) . '".';
                continue;
            }

            $detectedMime = mime_content_type($tmpName);
            if ($detectedMime === false || !in_array($detectedMime, $allowedMime, true)) {
                $fieldErrors['allegati'][] = 'Tipo di file non consentito per "' . escape($originalName) . '". Solo JPG, PNG, PDF.';
                continue;
            }

            if (strpos($detectedMime, 'image/') === 0) {
                $imageInfo = @getimagesize($tmpName);
                if ($imageInfo === false) {
                    $fieldErrors['allegati'][] = 'L\'immagine "' . escape($originalName) . '" non è valida.';
                    continue;
                }
            }

            try {
                $randomName = bin2hex(random_bytes(16)) . '.' . $extension;
            } catch (Exception $e) {
                $generalErrors[] = 'Errore interno nella gestione dei file. Riprovare più tardi.';
                logInternalError('random_bytes fallita: ' . $e->getMessage(), $logPath);
                break;
            }

            $fileQueue[] = [
                'tmp_name' => $tmpName,
                'new_name' => $randomName,
                'relative' => $uploadDirRelative . '/' . $randomName,
                'original' => $originalName,
            ];
        }
    }

    if (empty($generalErrors) && empty($fieldErrors)) {
        foreach ($fileQueue as $fileItem) {
            $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileItem['new_name'];
            if (!move_uploaded_file($fileItem['tmp_name'], $destination)) {
                $generalErrors[] = 'Impossibile salvare il file "' . escape($fileItem['original']) . '". Riprovare.';
                logInternalError('move_uploaded_file fallita verso: ' . $destination, $logPath);
                break;
            }
            $uploadedFiles[] = $fileItem['relative'];
        }

        if (!empty($generalErrors)) {
            foreach ($uploadedFiles as $relative) {
                $fullPath = $uploadDir . DIRECTORY_SEPARATOR . basename($relative);
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }
            $uploadedFiles = [];
        }
    }

    if (empty($generalErrors) && empty($fieldErrors)) {
        $csvHandle = @fopen($csvPath, 'ab');
        if ($csvHandle === false) {
            $generalErrors[] = 'Impossibile salvare la segnalazione. Riprovare più tardi.';
            logInternalError('Impossibile aprire il CSV per append: ' . $csvPath, $logPath);
        } else {
            if (ftell($csvHandle) === 0) {
                $header = [
                    'timestamp', 'nome', 'cognome', 'azienda', 'telefono', 'email', 'indirizzo',
                    'cap', 'citta', 'provincia', 'operazione', 'tipologia', 'locali', 'mq', 'prezzo',
                    'descrizione', 'uploads'
                ];
                fputcsv($csvHandle, $header, ';');
            }
            $csvRow = [
                date('c'),
                $formData['nome'],
                $formData['cognome'],
                $formData['azienda'],
                $formData['telefono'],
                $formData['email'],
                $formData['indirizzo'],
                $formData['cap'],
                $formData['citta'],
                $formData['provincia'],
                $formData['operazione'],
                $formData['tipologia'],
                $formData['locali'],
                $formData['mq'],
                $formData['prezzo'],
                $formData['descrizione'],
                implode('|', $uploadedFiles),
            ];
            if (fputcsv($csvHandle, $csvRow, ';') === false) {
                $generalErrors[] = 'Errore durante il salvataggio della segnalazione.';
                logInternalError('fputcsv fallita su: ' . $csvPath, $logPath);
            } else {
                $submissionSaved = true;
            }
            fclose($csvHandle);
        }
    }

    if ($submissionSaved && empty($generalErrors) && empty($fieldErrors)) {
        $subject = 'Nuova segnalazione immobile - Subito CASA Web';
        $bodyLines = [
            'Hai ricevuto una nuova segnalazione dal sito Subito CASA Web.',
            '',
            'Dati segnalazione:',
            'Nome: ' . $formData['nome'],
            'Cognome: ' . $formData['cognome'],
            'Nome azienda: ' . $formData['azienda'],
            'Telefono: ' . $formData['telefono'],
            'Email: ' . $formData['email'],
            'Indirizzo immobile: ' . $formData['indirizzo'],
            'CAP: ' . $formData['cap'],
            'Città: ' . $formData['citta'],
            'Provincia: ' . $formData['provincia'],
            'Tipo operazione: ' . ($operationOptions[$formData['operazione']] ?? $formData['operazione']),
            'Tipologia immobile: ' . ($propertyOptions[$formData['tipologia']] ?? $formData['tipologia']),
            'Locali/Camere: ' . $formData['locali'],
            'MQ: ' . $formData['mq'],
            'Prezzo: ' . $formData['prezzo'],
            'Descrizione:',
            $formData['descrizione'],
            '',
            'File caricati:',
        ];
        if (!empty($uploadedFiles)) {
            foreach ($uploadedFiles as $relativePath) {
                $bodyLines[] = $relativePath;
            }
        } else {
            $bodyLines[] = 'Nessun file allegato.';
        }
        $bodyLines[] = '';
        $bodyLines[] = 'Nota: per l\'invio di allegati o invii affidabili in produzione si consiglia l\'uso di PHPMailer o servizi email dedicati.';

        $body = implode("\r\n", $bodyLines);
        $headers = [];
        $headers[] = 'From: no-reply@subitocasaweb.local';
        $headers[] = 'Reply-To: ' . ($formData['email'] !== '' ? $formData['email'] : 'no-reply@subitocasaweb.local');
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';

        if (!@mail($admin_email, $subject, $body, implode("\r\n", $headers))) {
            $generalWarnings[] = 'Segnalazione salvata, ma invio email di notifica non riuscito.';
            logInternalError('mail() fallita verso: ' . $admin_email, $logPath);
        }
    }

    if ($submissionSaved && empty($generalErrors) && empty($fieldErrors)) {
        $successMessage = 'Grazie! La tua segnalazione è stata inviata correttamente.';
        $displayUploads = $uploadedFiles;
        foreach ($formData as $key => $value) {
            if ($key === 'operazione') {
                $formData[$key] = 'vendita';
            } elseif ($key === 'tipologia') {
                $formData[$key] = 'appartamento';
            } else {
                $formData[$key] = '';
            }
        }
        $privacyAccepted = false;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Segnala un immobile — Subito CASA Web</title>
    <style>
        body { margin: 0; font-family: "Poppins", Arial, sans-serif; background: #f4f7fb; color: #1d2a44; }
        .page { min-height: 100vh; display: flex; flex-direction: column; }
        .hero { background: linear-gradient(135deg, #0b3fad 0%, #0a6cf1 60%, #39bdf4 100%); color: #fff; padding: 72px 24px 64px; position: relative; overflow: hidden; }
        .hero::after { content: ""; position: absolute; inset: 0; background: url('data:image/svg+xml,%3Csvg width="400" height="400" viewBox="0 0 400 400" xmlns="http://www.w3.org/2000/svg"%3E%3Ccircle cx="50" cy="50" r="50" fill="rgba(255,255,255,0.05)"/%3E%3Ccircle cx="200" cy="150" r="90" fill="rgba(255,255,255,0.05)"/%3E%3Ccircle cx="320" cy="80" r="60" fill="rgba(255,255,255,0.05)"/%3E%3C/svg%3E') repeat; opacity: 0.35; }
        .hero > * { position: relative; z-index: 1; }
        .hero-content { max-width: 1040px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 32px; align-items: center; }
        .hero-text h1 { font-size: clamp(2.2rem, 4vw, 3.2rem); margin-bottom: 16px; line-height: 1.1; }
        .hero-text p { font-size: 1.05rem; margin: 0 0 20px; }
        .hero-badge { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 999px; background: rgba(255, 255, 255, 0.2); font-weight: 600; font-size: 0.9rem; margin-bottom: 18px; }
        .hero-details { background: rgba(255,255,255,0.12); border-radius: 16px; padding: 20px; backdrop-filter: blur(6px); }
        .hero-details h2 { margin: 0 0 12px; font-size: 1.25rem; }
        .hero-details ul { margin: 0; padding-left: 20px; list-style: disc; font-size: 0.95rem; }
        main { flex: 1; }
        .section { padding: 48px 24px; }
        .section.narrow { padding-top: 32px; }
        .section.overlap { margin-top: -64px; }
        .container { max-width: 1080px; margin: 0 auto; }
        .intro-card { background: #fff; border-radius: 16px; padding: 32px; box-shadow: 0 18px 40px rgba(11, 63, 173, 0.1); }
        .intro-card h2 { margin-top: 0; font-size: 1.8rem; color: #0b3fad; }
        .intro-card p { line-height: 1.6; color: #425170; }
        .steps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-top: 32px; }
        .step-card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 12px 24px rgba(11, 63, 173, 0.08); position: relative; overflow: hidden; }
        .step-card::before { content: attr(data-step); position: absolute; top: 18px; right: 18px; font-size: 0.85rem; font-weight: 600; color: rgba(11, 63, 173, 0.65); }
        .step-card h3 { margin-top: 0; font-size: 1.2rem; color: #0b3fad; }
        .step-card p { margin-bottom: 0; color: #425170; line-height: 1.5; }
        .form-section { background: linear-gradient(120deg, rgba(11,63,173,0.08), rgba(57,189,244,0.12)); }
        .form-wrapper { background: #fff; border-radius: 18px; padding: 40px; box-shadow: 0 20px 48px rgba(13, 54, 115, 0.12); }
        .form-header { margin-bottom: 32px; }
        .form-header h2 { margin: 0 0 8px; font-size: 1.9rem; color: #0b3fad; }
        .form-header p { margin: 0; color: #4b5a7a; }
    form { display: flex; flex-direction: column; gap: 20px; }
    fieldset { border: none; padding: 0; margin: 0; }
    fieldset + fieldset { margin-top: 24px; }
        legend { font-size: 1.05rem; font-weight: 600; color: #0b3fad; margin-bottom: 18px; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px 20px; }
    .field-group { display: flex; flex-direction: column; gap: 8px; }
    label { font-weight: 600; margin-bottom: 0; color: #1d2a44; }
        input[type="text"], input[type="email"], textarea, select { width: 100%; padding: 12px; border: 1px solid #d4dbea; border-radius: 10px; font-size: 0.95rem; transition: border-color 0.2s ease, box-shadow 0.2s ease; background: #fdfdff; }
        input[type="text"]:focus, input[type="email"]:focus, textarea:focus, select:focus { border-color: #0a6cf1; box-shadow: 0 0 0 3px rgba(10, 108, 241, 0.18); outline: none; }
        textarea { min-height: 160px; resize: vertical; }
    .field-error { color: #b42318; font-size: 0.82rem; margin: 0; }
        .alert { padding: 18px 20px; border-radius: 12px; margin-bottom: 18px; border: 1px solid transparent; font-size: 0.95rem; }
        .alert ul { margin: 8px 0 0; padding-left: 20px; }
        .alert.error { background: #fff2f0; border-color: #ffc7be; color: #7a1410; }
        .alert.success { background: #edfdf6; border-color: #a6f3c6; color: #114d2a; }
        .alert.warning { background: #fff8e6; border-color: #ffe1a8; color: #8a5b07; }
        button[type="submit"] { align-self: flex-start; background: linear-gradient(135deg, #0b3fad, #0a6cf1); color: #fff; border: none; border-radius: 12px; padding: 14px 24px; font-size: 1.05rem; font-weight: 600; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        button[type="submit"]:hover { transform: translateY(-2px); box-shadow: 0 12px 24px rgba(11, 63, 173, 0.28); }
        button[disabled] { background: #9ab9f6; cursor: not-allowed; box-shadow: none; transform: none; }
        .privacy-note { display: flex; gap: 12px; align-items: flex-start; background: #f6f8ff; padding: 16px; border-radius: 12px; border: 1px solid #dce4f6; }
        .privacy-note label { font-weight: 500; color: #1d2a44; }
        .note { font-size: 0.85rem; color: #4b5a7a; margin-top: 6px; }
        .footer { background: #0b1d3a; color: #d7e3ff; padding: 32px 24px; text-align: center; font-size: 0.9rem; margin-top: auto; }
        .footer strong { color: #fff; }
        @media (max-width: 768px) {
            .hero { padding: 56px 20px 48px; }
            .intro-card { padding: 24px; }
            .form-wrapper { padding: 28px 24px; }
            .form-grid { gap: 14px; }
            fieldset + fieldset { margin-top: 18px; }
            button[type="submit"] { width: 100%; justify-content: center; }
        }
        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; }
        }
    </style>
</head>
<body>
<div class="page">
    <header class="hero">
        <div class="hero-content">
            <div class="hero-text">
                <span class="hero-badge">Subito CASA Web · Castellammare di Stabia</span>
                <h1>Segnala un immobile — Subito CASA Web</h1>
                <p>Sede operativa: Via Amato, 17, 80053 Castellammare di Stabia (NA). Collabora con il nostro team e aiutaci a individuare i prossimi immobili da proporre ai clienti.</p>
                <p><strong>Guadagni</strong> per ogni trattativa conclusa sull'immobile segnalato e hai il supporto dei nostri consulenti in ogni fase.</p>
            </div>
            <div class="hero-details">
                <h2>Perché segnalarci un immobile</h2>
                <ul>
                    <li>Collaborazione trasparente e supporto dedicato.</li>
                    <li>Premio economico a vendita conclusa.</li>
                    <li>Processo guidato: ci occupiamo noi della gestione.</li>
                </ul>
            </div>
        </div>
    </header>

    <main>
        <section class="section overlap">
            <div class="container intro-card">
                <h2>Guadagna da casa con Subito CASA Web</h2>
                <p>Se conosci proprietari intenzionati a vendere o affittare un immobile, inviaci i loro dati nel massimo rispetto della privacy. Ti contatteremo per confermare la segnalazione e coordinare le prossime attività. Con Subito CASA Web collabori in modo semplice, senza vincoli o costi iniziali.</p>
            </div>
        </section>

        <section class="section narrow">
            <div class="container">
                <div class="steps-grid" aria-label="Come funziona la collaborazione">
                    <div class="step-card" data-step="STEP 1">
                        <h3>Raccogli il nominativo</h3>
                        <p>Parla con chi sta pensando di vendere o affittare. Assicurati che sia d'accordo a essere ricontattato dal nostro staff.</p>
                    </div>
                    <div class="step-card" data-step="STEP 2">
                        <h3>Compila il form</h3>
                        <p>Inserisci i dettagli dell'immobile e le informazioni utili per valutarlo. Più dati condividi, più veloce sarà la risposta.</p>
                    </div>
                    <div class="step-card" data-step="STEP 3">
                        <h3>Collabora con noi</h3>
                        <p>Un consulente Subito CASA Web ti aggiornerà sugli sviluppi e ti riconoscerà il compenso una volta conclusa la trattativa.</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="section form-section" id="form">
            <div class="container">
                <div class="form-wrapper">
                    <div class="form-header">
                        <h2>Inviaci la tua segnalazione</h2>
                        <p>Compila il form per collaborare con Subito CASA Web. I campi contrassegnati con * sono obbligatori.</p>
                    </div>

                    <?php if (!empty($generalErrors)): ?>
                        <div class="alert error" role="alert">
                            <strong>Correggi i seguenti errori:</strong>
                            <ul>
                                <?php foreach ($generalErrors as $error): ?>
                                    <li><?= escape($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if ($successMessage !== ''): ?>
                        <div class="alert success" role="status">
                            <p><?= escape($successMessage) ?></p>
                            <?php if (!empty($displayUploads)): ?>
                                <p>File caricati:</p>
                                <ul>
                                    <?php foreach ($displayUploads as $relative): ?>
                                        <li><a href="<?= escape($relative) ?>" target="_blank" rel="noopener noreferrer"><?= escape($relative) ?></a></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($generalWarnings)): ?>
                        <div class="alert warning" role="alert">
                            <ul>
                                <?php foreach ($generalWarnings as $warning): ?>
                                    <li><?= escape($warning) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" enctype="multipart/form-data" novalidate>
                        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token'] ?? '') ?>">

                        <fieldset>
                            <legend>Dati di contatto</legend>
                            <div class="form-grid">
                                <div class="field-group">
                                    <label for="nome">Nome *</label>
                                    <input type="text" id="nome" name="nome" placeholder="Mario" value="<?= escape($formData['nome']) ?>">
                                    <?php if (!empty($fieldErrors['nome'])): ?>
                                        <div class="field-error"><?= is_array($fieldErrors['nome']) ? escape(implode(' ', $fieldErrors['nome'])) : escape($fieldErrors['nome']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="cognome">Cognome</label>
                                    <input type="text" id="cognome" name="cognome" placeholder="Rossi" value="<?= escape($formData['cognome']) ?>">
                                    <?php if (!empty($fieldErrors['cognome'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['cognome']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="azienda">Nome azienda</label>
                                    <input type="text" id="azienda" name="azienda" placeholder="Azienda Srl" value="<?= escape($formData['azienda']) ?>">
                                    <?php if (!empty($fieldErrors['azienda'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['azienda']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="telefono">Telefono</label>
                                    <input type="text" id="telefono" name="telefono" placeholder="331 1234567" value="<?= escape($formData['telefono']) ?>">
                                    <?php if (!empty($fieldErrors['telefono'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['telefono']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="email">Email *</label>
                                    <input type="email" id="email" name="email" placeholder="nome@example.com" value="<?= escape($formData['email']) ?>" required>
                                    <?php if (!empty($fieldErrors['email'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['email']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend>Dati immobile</legend>
                            <div class="form-grid">
                                <div class="field-group">
                                    <label for="indirizzo">Indirizzo immobile</label>
                                    <input type="text" id="indirizzo" name="indirizzo" placeholder="Via esempio, 10" value="<?= escape($formData['indirizzo']) ?>">
                                    <?php if (!empty($fieldErrors['indirizzo'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['indirizzo']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="cap">CAP</label>
                                    <input type="text" id="cap" name="cap" placeholder="80053" value="<?= escape($formData['cap']) ?>">
                                    <?php if (!empty($fieldErrors['cap'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['cap']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="citta">Città</label>
                                    <input type="text" id="citta" name="citta" placeholder="Castellammare di Stabia" value="<?= escape($formData['citta']) ?>">
                                    <?php if (!empty($fieldErrors['citta'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['citta']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="provincia">Provincia</label>
                                    <input type="text" id="provincia" name="provincia" placeholder="NA" value="<?= escape($formData['provincia']) ?>">
                                    <?php if (!empty($fieldErrors['provincia'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['provincia']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="operazione">Tipo operazione</label>
                                    <select id="operazione" name="operazione">
                                        <?php foreach ($operationOptions as $value => $label): ?>
                                            <option value="<?= escape($value) ?>"<?= $formData['operazione'] === $value ? ' selected' : '' ?>><?= escape($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($fieldErrors['operazione'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['operazione']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="tipologia">Tipologia immobile</label>
                                    <select id="tipologia" name="tipologia">
                                        <?php foreach ($propertyOptions as $value => $label): ?>
                                            <option value="<?= escape($value) ?>"<?= $formData['tipologia'] === $value ? ' selected' : '' ?>><?= escape($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if (!empty($fieldErrors['tipologia'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['tipologia']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="locali">Locali / Camere</label>
                                    <input type="text" id="locali" name="locali" placeholder="3" value="<?= escape($formData['locali']) ?>">
                                    <?php if (!empty($fieldErrors['locali'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['locali']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="mq">MQ</label>
                                    <input type="text" id="mq" name="mq" placeholder="120" value="<?= escape($formData['mq']) ?>">
                                    <?php if (!empty($fieldErrors['mq'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['mq']) ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="field-group">
                                    <label for="prezzo">Prezzo (€)</label>
                                    <input type="text" id="prezzo" name="prezzo" placeholder="250000" value="<?= escape($formData['prezzo']) ?>">
                                    <?php if (!empty($fieldErrors['prezzo'])): ?>
                                        <div class="field-error"><?= escape($fieldErrors['prezzo']) ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </fieldset>

                        <fieldset>
                            <legend>Dettagli aggiuntivi</legend>
                            <div class="field-group">
                                <label for="descrizione">Descrizione libera</label>
                                <textarea id="descrizione" name="descrizione" placeholder="Inserisci informazioni aggiuntive sull'immobile."><?= escape($formData['descrizione']) ?></textarea>
                                <?php if (!empty($fieldErrors['descrizione'])): ?>
                                    <div class="field-error"><?= escape($fieldErrors['descrizione']) ?></div>
                                <?php endif; ?>
                            </div>

                            <div class="field-group">
                                <label for="allegati">Allega file (max <?= escape((string)$maxFiles) ?>, JPG/PNG/PDF, 5 MB ciascuno)</label>
                                <input type="file" id="allegati" name="allegati[]" multiple accept=".jpg,.jpeg,.png,.pdf">
                                <?php if (!empty($fieldErrors['allegati'])): ?>
                                    <?php if (is_array($fieldErrors['allegati'])): ?>
                                        <?php foreach ($fieldErrors['allegati'] as $fileError): ?>
                                            <div class="field-error"><?= escape($fileError) ?></div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="field-error"><?= escape($fieldErrors['allegati']) ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <p class="note">Suggerimento: rendere la cartella "uploads/" non eseguibile (es. .htaccess con "Options -ExecCGI" o regole del web server).</p>
                            </div>
                        </fieldset>

                        <div class="field-group">
                            <div class="privacy-note">
                                <input type="checkbox" id="privacy" name="privacy" value="1"<?= $privacyAccepted ? ' checked' : '' ?> required>
                                <label for="privacy">Accetto l'informativa sulla privacy e il trattamento dei dati per la gestione della richiesta.</label>
                            </div>
                            <?php if (!empty($fieldErrors['privacy'])): ?>
                                <div class="field-error"><?= escape($fieldErrors['privacy']) ?></div>
                            <?php endif; ?>
                        </div>

                        <button type="submit" id="submitBtn">Invia segnalazione</button>
                    </form>
                </div>
            </div>
        </section>
    </main>

    <footer class="footer">
        <p><strong>Subito CASA Web</strong> · Via Amato, 17, 80053 Castellammare di Stabia (NA) · Tel. +39 081 000000</p>
        <p>Per maggiore sicurezza applica header HTTP dedicati, limita i MIME lato server e valuta un sistema di rate limiting.</p>
    </footer>
</div>
<script>
(function() {
    var form = document.querySelector('form');
    var submitBtn = document.getElementById('submitBtn');
    if (!form || !submitBtn) {
        return;
    }
    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Invio in corso...';
        setTimeout(function() {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Invia segnalazione';
        }, 8000);
    });
})();
</script>
</body>
</html>

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
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/app.js" defer></script>
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
</body>
</html>

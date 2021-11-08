<?php
/**
 * mail forwarder
 * 
 * @author Henrik Gebauer <code@henrik-gebauer.de>
 * @license MIT License
 */

declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

require_once 'vendor/autoload.php';

function parseHeaders(string $header): array
{
    $headers = [];
    $name = '';
    foreach (explode("\n", trim($header)) as $line) {
        if ($line[0] !== " " && $line[0] !== "\t") {
            list($name, $value) = explode(':', $line, 2);
            $headers[$name] = trim($value);
        } else {
            $headers[$name] .= "\n" . $line;
        }
    }
    return $headers;
}

$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);

foreach (['user', 'password', 'senderAddress'] as $setting) {
    if (!is_array($config['target'][$setting])) {
        $config['target'][$setting] = [$config['target'][$setting]];
    }    
    if (count($config['target'][$setting]) === 1) {
        $config['target'][$setting] = array_fill(0, count($config['target']['user']), $config['target'][$setting]);
    }
    if (count($config['target'][$setting]) !== count($config['target']['user'])) {
        echo "Configuration is malformed: number of users does not match number of " . $setting . "s.\n";
        die();
    }
}

if (!empty($config['timeLimitInSeconds'])) {
    set_time_limit((int) $config['timeLimitInSeconds']);
}

$inbox = new PhpImap\Mailbox(
    "{{$config['source']['host']}:{$config['source']['port']}/imap/{$config['source']['secure']}}{$config['source']['folder']}",
    $config['source']['user'],
    $config['source']['password']
);

$inbox->setConnectionArgs(
    CL_EXPUNGE // expunge deleted mails upon mailbox close
    | OP_SECURE // don't do non-secure authentication
);

try {
    $mailsIds = $inbox->searchMailbox('ALL');
} catch(PhpImap\Exceptions\ConnectionException $ex) {
    echo "IMAP connection failed: " . $ex->getMessage();
    die();
}

$outboxUser = 0;
$userReuseCounter = 0;

foreach ($mailsIds as $mailId) {
    $mail = $inbox->getMail($mailId);

    if (isset($outbox)) {
        $userReuseCounter++;
        if (!empty($config['target']['reuseLimit'])) {
            $userReuseCounter %= $config['target']['reuseLimit'];
        }
        if ($userReuseCounter === 0 || empty($config['target']['reuseLimit'])) {
            $outboxUser++;
            $outboxUser %= count($config['target']['user']);
            if ($outboxUser === 0 && !empty($config['target']['pauseAfterAllUsersInSeconds'])) {
                sleep($config['target']['pauseAfterAllUsersInSeconds']);
            }    
            $outbox->smtpClose();
            unset($outbox);
        } else {
            $outbox->clearAddresses();
            $outbox->clearReplyTos();
            $outbox->clearCustomHeaders();        
        }
    }

    if (!isset($outbox)) {
        $outbox = new PHPMailer();
        $outbox->isSMTP();
        $outbox->Host = $config['target']['host'];
        $outbox->SMTPAuth = true;
        $outbox->SMTPSecure = $config['target']['secure'];
        $outbox->Port = $config['target']['port'];
        $outbox->CharSet = "UTF-8";

        $outbox->Username = $config['target']['user'][$outboxUser];
        $outbox->Password = $config['target']['password'][$outboxUser];
        $outbox->setFrom($config['target']['senderAddress'][$outboxUser], $mail->senderName);
    }

    $forwardedFor = [];
    $hasReplyToHeader = false;

    foreach ($mail->to as $address=>$name) {
        if (preg_match('/^[^\+]+\+([^@]+)@/', $address, $matches)) {
            $outbox->addAddress($matches[1] . "@" . $config['target']['domain'], $name);
            $forwardedFor[] = $matches[1];
        }
    }
    if (!$forwardedFor) {
        continue;
    }

    $outbox->Subject = $mail->subject;
    $outbox->MessageID = $mail->messageId;
    $outbox->MessageDate = $mail->headers->date;
    $outbox->addCustomHeader('X-Forwarded-From', $mail->senderAddress);
    $outbox->addCustomHeader('X-Forwarded-For', implode(', ', $forwardedFor));

    $headers = parseHeaders($mail->headersRaw);
    foreach ($headers as $name => &$value) {
        if ($name === 'Reply-To') {
            $hasReplyToHeader = true;
        }
        if (in_array($name, [
            'List-Id', 'List-Help', 'X-Course-Id', 'X-Course-Name', 'Precedence',
            'X-Auto-Response-Suppress', 'Auto-Submitted', 'List-Unsubscribe',
            'Thread-Topic', 'Thread-Index', 'In-Reply-To', 'Reply-To'
        ], true)) {
            $value = $inbox->decodeMimeStr($value);
            $outbox->addCustomHeader($name, $value);
        }
    }

    if (!$hasReplyToHeader) {
        $outbox->addReplyTo($mail->senderAddress, $mail->senderName);
    }

    if ($mail->textHtml) {
        $outbox->isHTML(true);
        $outbox->Body = $mail->textHtml;
        $outbox->AltBody = $mail->textPlain;
    } else {
        $outbox->isHTML(false);
        $outbox->Body = $mail->textPlain;
        $outbox->AltBody = '';
    }
    
    if ($config['allowedSenderAddresses']) {
        $senderIsAllowed = false;
        foreach ($config['allowedSenderAddresses'] as $address) {
            if ($address[0] === '/') {
                if (preg_match($address, $mail->senderAddress)) {
                    $senderIsAllowed = true;
                    break;
                }
            } elseif (strtolower($address) === strtolower($mail->senderAddress)) {
                $senderIsAllowed = true;
                break;
            }
        }
        if (!$senderIsAllowed) {
            $outbox->Subject = "mail rejected: " . $outbox->Subject;
            $outbox->clearAddresses();
            $outbox->addAddress($config['abuseAddress']);
        }
    }

    if ($outbox->send()) {
        $inbox->deleteMail($mailId);
    } else {
        echo $outbox->ErrorInfo . "\n";
        exit;
    }
}

$inbox->disconnect();

echo "done\n";


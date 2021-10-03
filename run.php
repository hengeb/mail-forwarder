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

$config = json_decode(file_get_contents('config.json'), true);

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
	echo "IMAP connection failed: " . $ex;
	die();
}

$outbox = new PHPMailer();
$outbox->isSMTP();
$outbox->Host = $config['target']['host'];
$outbox->SMTPAuth = true;
$outbox->Username = $config['target']['user'];
$outbox->Password = $config['target']['password'];
$outbox->SMTPSecure = $config['target']['secure'];
$outbox->Port = $config['target']['port'];
$outbox->CharSet = "UTF-8";

foreach ($mailsIds as $mailId) {
    $outbox->clearAddresses();
    $outbox->clearReplyTos();
    $outbox->clearCustomHeaders();

    $forwardedFor = [];

    $mail = $inbox->getMail($mailId);

    $outbox->setFrom($config['target']['senderAddress'], $mail->senderName);
    
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
        if (in_array($name, [
            'List-Id', 'List-Help', 'X-Course-Id', 'X-Course-Name', 'Precedence',
            'X-Auto-Response-Suppress', 'Auto-Submitted', 'List-Unsubscribe',
            'Thread-Topic', 'Thread-Index', 'In-Reply-To', 'Reply-To'
        ], true)) {
            $value = $inbox->decodeMimeStr($value);
            $outbox->addCustomHeader($name, $value);
        }
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
    
    if ($config['allowedSenderAddresses'] && !in_array($mail->senderAddress, $config['allowedSenderAddresses'], true)) {
        $outbox->Subject = "mail rejected: " . $outbox->Subject;
        $outbox->clearAddresses();
        $outbox->addAddress($config['abuseAddress']);
    }

    if ($outbox->send()) {
        $inbox->deleteMail($mailId);
    } else {
        echo $outbox->ErrorInfo . "\n";
        exit;
    }
}

$inbox->disconnect();
$outbox->smtpClose();

echo "done\n";


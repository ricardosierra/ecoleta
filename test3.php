<?php
require_once __DIR__ . '/public/api/env.php';
require_once __DIR__ . '/public/api/os/os_lib.php';

echo "Testando envio autenticado (PHPMailer SMTP)...\n";
$subject = "Teste SMTP PHPMailer - Ecoleva";
$html = "<h2>Sucesso!</h2><p>O envio autenticado via SMTP Hostinger (contato@ecolevaeco.com) funcionou perfeitamente!</p>";
$text = "Sucesso! O envio via SMTP funcionou.";

$result = osSendMail('sierra.csi@gmail.com', $subject, $html, $text);

echo $result ? "✅ E-mail enviado com sucesso via SMTP Hostinger!\n" : "❌ Falha no envio.\n";

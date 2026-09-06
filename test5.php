<?php
require_once __DIR__ . '/public/api/env.php';
require_once __DIR__ . '/public/api/asaas_lib.php';
require_once __DIR__ . '/public/api/os/os_lib.php';

echo "Enviando fatura de teste com boleto e juros...\n";
try {
    $customerId = asaasCreateCustomer('Malu Alves', 'malualveszs@hotmail.com', '12345678909', null);
    $payment = asaasCreatePayment($customerId, 5.00, date('Y-m-d', strtotime('+5 days')));
    $qrCode = asaasGetPixQrCode($payment['id']);
    
    $client = ['name' => 'Malu Alves', 'monthly_value' => 5.00, 'email' => 'malualveszs@hotmail.com'];
    $dueDateStr = date('Y-m-d', strtotime('+5 days'));
    
    $valorFormatado = number_format((float)$client['monthly_value'], 2, ',', '.');
    $vencimentoFormatado = date('d/m/Y', strtotime($dueDateStr));
    
    $qrPayloadUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' . urlencode($qrCode['payload']);
    $faturaUrl = $payment['invoiceUrl'];
    
    $htmlBody = <<<HTML
<div style="background:#F4F6F8;padding:40px 20px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;">
  <div style="max-width:600px;margin:0 auto;background:#FFFFFF;border-radius:4px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
    
    <!-- Cabeçalho estilo Asaas -->
    <div style="background:#2D5934;padding:32px;color:#FFFFFF;">
      <h2 style="margin:0;font-size:18px;font-weight:700;text-transform:uppercase;">ECOLEVA SOLUCOES AMBIENTAIS LTDA.</h2>
      <div style="font-size:14px;margin-top:8px;opacity:0.9;">57.772.812/0001-72</div>
    </div>
    
    <div style="padding:40px 32px;">
      <h1 style="margin:0 0 24px 0;font-size:22px;color:#333333;text-align:center;border-bottom:1px solid #EEEEEE;padding-bottom:24px;">Sua Fatura Mensal</h1>
      
      <p style="font-size:15px;color:#333333;margin-bottom:16px;">Olá, <strong>{$client['name']}</strong></p>
      
      <p style="font-size:15px;color:#333333;line-height:1.6;margin-bottom:24px;">
        A ECOLEVA SOLUCOES AMBIENTAIS LTDA. gerou uma cobrança para você, no valor de <strong>R$ {$valorFormatado}</strong> com vencimento em <strong>{$vencimentoFormatado}</strong>.
      </p>
      
      <p style="font-size:13px;color:#B91C1C;background:#FEF2F2;border-left:4px solid #EF4444;padding:12px;border-radius:0 4px 4px 0;margin-bottom:32px;line-height:1.5;">
        <strong>⚠️ Atenção:</strong> O pagamento após o dia {$vencimentoFormatado} acarretará na cobrança de juros e multa.
      </p>
      
      <!-- Box do Pix -->
      <div style="background:#F9FAFB;border:1px solid #E5E7EB;border-radius:8px;padding:24px;text-align:center;margin-bottom:24px;">
        <h3 style="margin:0 0 16px 0;color:#10B981;font-size:18px;">Pague via Pix</h3>
        <p style="margin:0 0 16px 0;color:#6B7280;font-size:14px;">Escaneie o QR Code abaixo com o aplicativo do seu banco:</p>
        
        <img src="{$qrPayloadUrl}" alt="QR Code Pix" style="width:200px;height:200px;margin:0 auto;display:block;" />
        
        <div style="margin-top:24px;">
          <p style="margin:0 0 8px 0;color:#6B7280;font-size:14px;">Ou copie o código abaixo (Pix Copia e Cola):</p>
          <div style="background:#FFFFFF;border:1px dashed #D1D5DB;border-radius:4px;padding:12px;font-family:monospace;font-size:12px;color:#374151;word-break:break-all;text-align:left;">
            {$qrCode['payload']}
          </div>
        </div>
      </div>
      
      <!-- Box do Boleto -->
      <div style="background:#FFFFFF;border:1px solid #E5E7EB;border-radius:8px;padding:24px;text-align:center;margin-bottom:8px;">
        <h3 style="margin:0 0 12px 0;color:#374151;font-size:16px;">Prefere pagar via Boleto?</h3>
        <p style="margin:0 0 20px 0;color:#6B7280;font-size:14px;">Acesse a fatura completa para gerar o seu boleto bancário:</p>
        <a href="{$faturaUrl}" style="display:inline-block;background:#2D5934;color:#FFFFFF;text-decoration:none;font-weight:600;font-size:14px;border-radius:50px;padding:12px 32px;">Visualizar Boleto</a>
      </div>
      
    </div>
    
    <!-- Rodapé -->
    <div style="background:#F9FAFB;padding:24px;text-align:center;border-top:1px solid #EEEEEE;">
      <p style="margin:0 0 4px 0;font-size:12px;font-weight:700;color:#4B5563;">ECOLEVA SOLUCOES AMBIENTAIS LTDA.</p>
      <p style="margin:0 0 4px 0;font-size:12px;color:#6B7280;">57.772.812/0001-72</p>
      <p style="margin:0 0 4px 0;font-size:12px;color:#6B7280;">diretoria@econformidade.com.br</p>
      <p style="margin:0 0 4px 0;font-size:12px;color:#6B7280;">(21) 99152-9383</p>
      <p style="margin:0;font-size:12px;color:#2D5934;">RUA DR FRANCISCO DE SOUZA, 18, SALA 302, CENTRO<br>CEP: 28800000<br>Rio Bonito - RJ</p>
    </div>
    
  </div>
</div>
HTML;
    
    $textBody = "Olá, {$client['name']}. Sua fatura (Teste) da Ecoleva (R$ {$valorFormatado}, vencimento: {$vencimentoFormatado}) está disponível. Pix Copia e Cola: {$qrCode['payload']}";
    
    $mailOk = osSendMail($client['email'], 'Sua Fatura Mensal - Ecoleva', $htmlBody, $textBody);
    echo $mailOk ? " -> Teste enviado com sucesso para a cliente!\n" : " -> Falha ao enviar o e-mail.\n";
} catch (Throwable $e) {
    echo " -> Erro no Asaas: " . $e->getMessage() . "\n";
}

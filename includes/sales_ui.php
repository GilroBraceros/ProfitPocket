<?php
declare(strict_types=1);

// Presentation helpers only. Financial validation stays in includes/sales.php.
function sale_modal_request(): bool
{
    return ($_GET['modal'] ?? '') === '1';
}

function sale_ui_json(bool $success, string $message, array $extra = []): void
{
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(['success'=>$success,'message'=>$message] + $extra, JSON_THROW_ON_ERROR);
    exit;
}

function sale_ui_errors(string $message, array $input): array
{
    // Match the existing backend's first validation error to a visible field.
    // The full message is always shown as well, including errors with no field.
    $rules = [
        'Payment notes'=>'payment_notes',
        'bank name'=>'provider_name','wallet provider name'=>'provider_name','bank or wallet'=>'provider_name','wallet'=>'wallet_method_id','Cash, Digital'=>'payment_category',
        'customer'=>'customer_id', 'due date'=>'due_date', 'sale date'=>'sold_at',
        'discount'=>'discount_value', 'payment method'=>'payment_method_id',
        'payment reference'=>'payment_reference', 'payment amount'=>'initial_payment',
        'Payment cannot'=>'initial_payment', 'Payment must'=>'initial_payment',
        'Payment type'=>'payment_plan', 'Notes'=>'notes', 'void reason'=>'void_reason',
        'refund'=>'refund_confirm',
    ];
    foreach ($rules as $phrase=>$field) {
        if (stripos($message,$phrase)!==false) { return [$field=>$message]; }
    }
    foreach (['Quantity'=>'quantity','Quantities'=>'quantity','Unit price'=>'unit_price','product'=>'product_id','sale item'=>'product_id'] as $phrase=>$field) {
        if (stripos($message,$phrase)!==false) {
            $rows=is_array($input['items']??null)?$input['items']:[];
            $index=array_key_first($rows)??0;
            // Locate malformed quantities/prices when several items are present.
            foreach ($rows as $i=>$row) {
                if (!is_array($row)) { continue; }
                $value=$row[$field]??'';
                $scale=$field==='quantity'?3:2;
                if ($field!=='product_id' && (!is_string($value) || !preg_match('/\A[0-9]+(?:\.[0-9]{1,'.$scale.'})?\z/',$value) || ($field==='quantity' && bccomp($value,'0',3)<=0))) { $index=$i; break; }
            }
            return ['items['.$index.']['.$field.']'=>$message];
        }
    }
    return [];
}

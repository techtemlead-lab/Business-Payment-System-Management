<?php
namespace App\Domain\Payouts;
use App\Models\User;
use App\Support\{Money,Audit};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
class ManualPayoutService {
    public function review(User $actor,string $id,string $decision,string $reason):void {
        abort_unless($actor->allows('finance'),403);
        if (!config('madina.financial_activation')) throw ValidationException::withMessages(['policy'=>'Payout recording is inactive until an approved financial policy and opening balances are verified.']);
        DB::transaction(function()use($actor,$id,$decision,$reason){
            $w=DB::table('withdrawals')->where('id',$id)->lockForUpdate()->first();abort_unless($w,404);
            if($w->status!=='requested') throw ValidationException::withMessages(['withdrawal'=>'This request has already been reviewed.']);
            if($actor->id===$w->user_id) throw ValidationException::withMessages(['withdrawal'=>'You cannot review your own withdrawal.']);
            $wallet=DB::table('wallets')->where('user_id',$w->user_id)->lockForUpdate()->first();
            if(!$wallet||Money::cents($wallet->held)<Money::cents($w->amount)) throw ValidationException::withMessages(['withdrawal'=>'Reserved funds do not reconcile. Review the ledger first.']);
            if($decision==='rejected') DB::table('wallets')->where('id',$wallet->id)->update(['held'=>Money::decimal(Money::cents($wallet->held)-Money::cents($w->amount)),'available'=>Money::decimal(Money::cents($wallet->available)+Money::cents($w->amount)),'updated_at'=>now()]);
            DB::table('withdrawals')->where('id',$id)->update(['status'=>$decision,'reviewed_by'=>$actor->id,'reason'=>$reason,'updated_at'=>now()]);
            DB::table('withdrawal_events')->insert(['withdrawal_id'=>$id,'actor_id'=>$actor->id,'event'=>$decision,'note'=>$reason,'created_at'=>now()]);
            Audit::record($actor->id,'withdrawal.'.$decision,$id);Audit::notice($w->user_id,'Withdrawal reviewed','Your withdrawal was '.$decision.'. '.$reason);
        },3);
    }
    public function settle(User $actor,string $id,UploadedFile $receipt,string $reference,string $paidAt,string $note):void {
        abort_unless($actor->allows('finance'),403);
        if (!config('madina.financial_activation')) throw ValidationException::withMessages(['policy'=>'Payout recording is inactive until an approved financial policy and opening balances are verified.']);
        $path=$receipt->store('payout-receipts','local');
        try {
            DB::transaction(function()use($actor,$id,$receipt,$path,$reference,$paidAt,$note){
                $w=DB::table('withdrawals')->where('id',$id)->lockForUpdate()->first();abort_unless($w,404);
                if($w->status!=='approved') throw ValidationException::withMessages(['withdrawal'=>'Only an approved request can be recorded as paid.']);
                if($actor->id===$w->user_id||$actor->id===$w->reviewed_by) throw ValidationException::withMessages(['withdrawal'=>'Payment recording needs a different authorized staff member from the reviewer.']);
                if(DB::table('withdrawals')->where('payment_reference',$reference)->exists()) throw ValidationException::withMessages(['payment_reference'=>'This payment reference is already recorded.']);
                $wallet=DB::table('wallets')->where('user_id',$w->user_id)->lockForUpdate()->first();
                if(!$wallet||Money::cents($wallet->held)<Money::cents($w->amount)) throw ValidationException::withMessages(['withdrawal'=>'Reserved funds do not reconcile.']);
                DB::table('wallets')->where('id',$wallet->id)->update(['held'=>Money::decimal(Money::cents($wallet->held)-Money::cents($w->amount)),'updated_at'=>now()]);
                $entry=(string)Str::uuid(); DB::table('journal_entries')->insert(['id'=>$entry,'event_key'=>'manual-payout:'.$id,'description'=>'Manual payout recorded with receipt','actor_id'=>$actor->id,'created_at'=>now()]);
                DB::table('journal_lines')->insert([['entry_id'=>$entry,'user_id'=>$w->user_id,'account'=>'wallet_liability','debit'=>$w->amount,'credit'=>'0.00'],['entry_id'=>$entry,'user_id'=>null,'account'=>'manual_payout_clearing','debit'=>'0.00','credit'=>$w->amount]]);
                DB::table('withdrawals')->where('id',$id)->update(['status'=>'paid','payment_reference'=>$reference,'paid_at'=>$paidAt,'paid_by'=>$actor->id,'receipt_path'=>$path,'receipt_mime'=>$receipt->getMimeType(),'receipt_sha256'=>hash_file('sha256',$receipt->getRealPath()),'updated_at'=>now()]);
                DB::table('withdrawal_events')->insert(['withdrawal_id'=>$id,'actor_id'=>$actor->id,'event'=>'paid','note'=>$note,'created_at'=>now()]);
                Audit::record($actor->id,'withdrawal.paid',$id,['receipt_sha256'=>hash_file('sha256',$receipt->getRealPath())]);Audit::notice($w->user_id,'Manual payment recorded','Your withdrawal payment and receipt are available in withdrawal history.');
            },3);
        } catch(\Throwable $e) {Storage::disk('local')->delete($path);throw $e;}
    }
}

<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use App\Domain\Payouts\ManualPayoutService;
use App\Support\Audit;
use Inertia\Inertia;
class WithdrawalController extends Controller {
    public function show(Request $r,string $id) {
        $w=DB::table('withdrawals')->where('id',$id)->first();abort_unless($w&&($w->user_id===$r->user()->id||$r->user()->allows('finance')),404);
        $destination=decrypt($w->destination);
        return Inertia::render('WithdrawalDetail',['withdrawal'=>collect((array)$w)->except(['receipt_path','receipt_sha256','destination'])->all()+['destination_masked'=>str_repeat('•',max(0,strlen($destination)-4)).substr($destination,-4),'has_receipt'=>(bool)$w->receipt_path],
            'events'=>DB::table('withdrawal_events')->join('users','users.id','=','actor_id')->where('withdrawal_id',$id)->orderBy('withdrawal_events.id')->get(['event','note','withdrawal_events.created_at','users.name'])]);
    }
    public function review(Request $r,string $id,ManualPayoutService $s) {
        abort_unless($r->user()->allows('finance'),403);$d=$r->validate(['decision'=>'required|in:approved,rejected','reason'=>'required|string|max:1000']);
        $s->review($r->user(),$id,$d['decision'],$d['reason']);return back()->with('success','Withdrawal review recorded. No money has been sent by the application.');
    }
    public function settle(Request $r,string $id,ManualPayoutService $s) {
        abort_unless($r->user()->allows('finance'),403);$d=$r->validate(['receipt'=>'required|file|mimes:pdf,jpg,jpeg,png|max:5120','payment_reference'=>'required|string|max:100','paid_at'=>['required','date',function($attribute,$value,$fail){if(\Carbon\Carbon::parse($value,'Asia/Dhaka')->isFuture())$fail('Payment time cannot be in the future.');}],'note'=>'required|string|max:1000']);
        $s->settle($r->user(),$id,$r->file('receipt'),strtoupper(trim($d['payment_reference'])),\Carbon\Carbon::parse($d['paid_at'],'Asia/Dhaka')->utc()->toDateTimeString(),$d['note']);return back()->with('success','Manual payment recorded with a private receipt.');
    }
    public function receipt(Request $r,string $id) {
        $w=DB::table('withdrawals')->where('id',$id)->first();abort_unless($w&&($w->user_id===$r->user()->id||$r->user()->allows('finance')),404);abort_unless($w->receipt_path&&Storage::disk('local')->exists($w->receipt_path),404);
        Audit::record($r->user()->id,'receipt.downloaded',$id);$ext=match($w->receipt_mime){'application/pdf'=>'pdf','image/png'=>'png',default=>'jpg'};
        return Storage::disk('local')->download($w->receipt_path,'payment-receipt-'.$id.'.'.$ext,['Content-Type'=>$w->receipt_mime,'Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
}

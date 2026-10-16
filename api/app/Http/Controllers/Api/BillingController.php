<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * 「料金」ページの裏側。
 *
 * 請求書そのものは案件の下（/projects/{project}/invoices）にあるが、料金ページは
 * 「これまで払ったもの」を案件をまたいで一覧したい。案件を1つずつ引くと顧客の
 * ブラウザが案件の数だけ往復するので、横断の読み取り専用エンドポイントを置く。
 *
 * 書き込みは一切ここに置かない（発行・送付・入金の記録は InvoiceController のまま）。
 */
class BillingController extends Controller
{
    public function invoices(Request $request)
    {
        return InvoiceResource::collection(
            $this->visible($request)
                ->with(['project.company', 'items'])
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function invoice(Request $request, Invoice $invoice)
    {
        $found = $this->visible($request)->whereKey($invoice->getKey())->exists();

        if (! $found) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return new InvoiceResource($invoice->load(['project.company', 'items']));
    }

    /**
     * この利用者に見えてよい請求書。
     *
     * 顧客に見せるのは自分の会社宛のものだけ。さらに下書きは除く——金額が確定して
     * いない社内メモであり、送付した時点（status=sent）で初めて相手のものになる。
     */
    private function visible(Request $request): Builder
    {
        $user = $request->user();

        if ($user->role === 'developer') {
            return Invoice::query();
        }

        return Invoice::query()
            ->whereIn('company_id', $user->companies()->pluck('companies.id'))
            ->where('status', '!=', 'draft');
    }
}

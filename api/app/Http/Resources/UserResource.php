<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /*
             * メールアドレスは developer と本人にだけ返す。
             *
             * このリソースは他ユーザーの入れ子（通話の参加者一覧・招待先候補・
             * メッセージの投稿者）としても使われるため、素で返すと
             * GET /projects/{p}/calls/invitees が全 developer のメールを
             * 任意のクライアントに渡してしまう。
             * メールを表示している画面は CompaniesView / ClientsView の
             * 2つだけで、どちらも developer 専用なので UI には影響しない。
             */
            'email' => $this->when(
                $request->user()?->role === 'developer' || $request->user()?->id === $this->id,
                fn () => $this->email
            ),
            'role' => $this->role,
            'avatar_path' => $this->avatar_path,
        ];
    }
}

// デモモードはビルド時フラグで決まる（デモサイトのみ true）。
// デモは専用DB＋DemoSeederの架空データのみなので、developer/client 両方を安全に公開できる。
// 本番ビルドでは false になり、ログイン画面にデモ情報は一切表示されない。
export const IS_DEMO = import.meta.env.VITE_DEMO_MODE === "true"

// ログイン画面に表示する公開デモアカウント。
// api/database/seeders/DemoSeeder.php と資格情報を一致させること。
// IS_DEMO でゲートすることで、本番ビルドでは配列リテラルごと tree-shake され、
// 資格情報の文字列が prod バンドルから物理的に除去される（デモビルドにのみ残る）。
export const DEMO_ACCOUNTS = IS_DEMO
  ? [
      {
        role: "developer",
        labelKey: "login.demo.developerLabel",
        email: "demo-dev@parlour.takumi.ca",
        password: "demoparlour",
      },
      {
        role: "client",
        labelKey: "login.demo.clientLabel",
        email: "demo-client@parlour.takumi.ca",
        password: "demoparlour",
      },
    ]
  : []

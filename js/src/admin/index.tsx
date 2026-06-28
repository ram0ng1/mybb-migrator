import app from "flarum/admin/app";

import MigratorDashboard from "./components/MigratorDashboard";

app.initializers.add("ramon-mybb-migrator", () => {
  app.registry
    .for("ramon-mybb-migrator")
    .registerSetting(
      () => <MigratorDashboard />,
      100,
      "ramon-mybb-migrator.dashboard",
    );
});

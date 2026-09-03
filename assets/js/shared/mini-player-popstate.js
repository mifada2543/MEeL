



window.meelMiniPlayerPopstate = function (options) {
  const opts = options || {};
  const isActive =
    opts.isActive ||
    function () {
      return false;
    };
  const getWatchUrl =
    opts.watchUrl ||
    function () {
      return "";
    };
  const onExit = opts.onExit || function () {};
  window.addEventListener("popstate", (e) => {
    if (!isActive()) return;
    const backToMiniEntry = !!(e.state && e.state.miniPlayer);
    if (backToMiniEntry || window.location.href === getWatchUrl()) {
      onExit();
    }
  });
};

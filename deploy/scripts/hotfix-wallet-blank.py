#!/usr/bin/env python3
"""Hotfix Synology frontend assets for blank kid wallet page. Run on NAS host."""
from __future__ import annotations

import re
from pathlib import Path

INDEX = Path("/var/www/frontend/dist/assets/index-Du94Dk5I.js")
WALLET = Path("/var/www/frontend/dist/assets/KidWalletPage-CRQB5cVe.js")


def patch_index() -> None:
    t = INDEX.read_text(encoding="utf-8")
    # Planet availability guard: do not bounce to a blank home when /kid/home fails.
    needle = "planets.some(e=>e.key===t))return{name:`kid-home`}})catch{return{name:`kid-home`}}"
    repl = "planets.some(e=>e.key===t))return{name:`kid-home`}})catch{return!0}"
    if needle not in t:
        # Show nearby context for debugging
        i = t.find("planets.some")
        raise SystemExit(f"index needle missing near: {t[i:i+180]!r}")
    INDEX.write_text(t.replace(needle, repl, 1), encoding="utf-8")
    print("index patched")


def patch_wallet() -> None:
    t = WALLET.read_text(encoding="utf-8")
    repls = [
        ("V.value.xp.balance", "(V.value.xp&&V.value.xp.balance||0)"),
        ("V.value.xp.level", "(V.value.xp&&V.value.xp.level||1)"),
        ("V.value.xp.to_next", "(V.value.xp&&V.value.xp.to_next||0)"),
        ("V.value.xp.progress", "(V.value.xp&&V.value.xp.progress||0)"),
        ("V.value.xp.lifetime_earned", "(V.value.xp&&V.value.xp.lifetime_earned||0)"),
        ("V.value.points.available", "(V.value.points&&V.value.points.available||0)"),
        ("V.value.points.held", "(V.value.points&&V.value.points.held||0)"),
        ("V.value.coin.available", "(V.value.coin&&V.value.coin.available||0)"),
        ("V.value.coin.held", "(V.value.coin&&V.value.coin.held||0)"),
    ]
    orig = t
    for a, b in repls:
        t = t.replace(a, b)
    WALLET.write_text(t, encoding="utf-8")
    print("wallet js patched", t != orig)


def simplify_wallet_controller(host_path: str) -> None:
    p = Path(host_path)
    text = p.read_text(encoding="utf-8")
    text2 = re.sub(
        r"\n\s*// Existing XP.*?\$xp = \$xp->fresh\(\) \?\? \$xp;\n",
        "\n",
        text,
        count=1,
        flags=re.S,
    )
    if "reconcileLevels" not in text2:
        text2 = text2.replace("use App\\Modules\\Core\\Support\\Clock;\n", "")
        text2 = re.sub(
            r",\n\s*private readonly Clock \$clock,\n\s*\)",
            "\n    )",
            text2,
        )
    p.write_text(text2, encoding="utf-8")
    print("controller simplified", "reconcileLevels" not in text2)


if __name__ == "__main__":
    import sys

    if len(sys.argv) > 1 and sys.argv[1] == "controller":
        simplify_wallet_controller(sys.argv[2])
    else:
        patch_index()
        patch_wallet()

<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { playTap } from '@/shared/sfx'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const session = useSessionStore()

function open(name: 'kid-schulte' | 'kid-mirror' | 'kid-sudoku'): void {
  if (session.me?.child?.preferences.sounds) playTap()
  void router.push({ name })
}
</script>

<template>
  <main class="page">
    <header class="head">
      <button type="button" class="back" @click="router.push({ name: 'kid-home' })">{{ t('kid.backPlanet') }}</button>
      <h1>{{ t('kid.gamesTitle') }}</h1>
      <span class="spacer" />
    </header>

    <p class="intro">{{ t('kid.games.intro') }}</p>

    <div class="list">
      <button type="button" class="card" @click="open('kid-schulte')">
        <span class="emoji" aria-hidden="true">🔢</span>
        <span class="copy">
          <span class="name">{{ t('kid.games.schulte') }}</span>
          <span class="blurb">{{ t('kid.games.schulteBlurb') }}</span>
        </span>
        <span class="open">{{ t('kid.games.open') }}</span>
      </button>

      <button type="button" class="card" @click="open('kid-mirror')">
        <span class="emoji" aria-hidden="true">🪞</span>
        <span class="copy">
          <span class="name">{{ t('kid.games.mirror') }}</span>
          <span class="blurb">{{ t('kid.games.mirrorBlurb') }}</span>
        </span>
        <span class="open">{{ t('kid.games.open') }}</span>
      </button>

      <button type="button" class="card" @click="open('kid-sudoku')">
        <span class="emoji" aria-hidden="true">9️⃣</span>
        <span class="copy">
          <span class="name">{{ t('kid.games.sudoku') }}</span>
          <span class="blurb">{{ t('kid.games.sudokuBlurb') }}</span>
        </span>
        <span class="open">{{ t('kid.games.open') }}</span>
      </button>
    </div>
  </main>
</template>

<style scoped>
.page {
  width: 100%;
  padding: 0 var(--kid-gutter) var(--kid-page-bottom);
  max-width: var(--kid-content-max);
  margin: 0 auto;
}
.head {
  display: flex;
  align-items: center;
  margin-bottom: 16px;
}
.head h1 {
  flex: 1;
  text-align: center;
  margin: 0;
  font-size: 22px;
}
.spacer {
  width: 72px;
}
.back {
  border: none;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  padding: 10px 14px;
  border-radius: 999px;
  font-size: 14px;
  cursor: pointer;
  touch-action: manipulation;
}
.intro {
  margin: 0 0 16px;
  text-align: center;
  opacity: 0.85;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.card {
  width: 100%;
  min-height: 112px;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px;
  border: none;
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  text-align: left;
  cursor: pointer;
  touch-action: manipulation;
}
.emoji {
  font-size: 44px;
  line-height: 1;
}
.copy {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}
.name {
  font-size: 20px;
  font-weight: 800;
}
.blurb {
  font-size: 14px;
  opacity: 0.8;
}
.open {
  flex: none;
  padding: 8px 12px;
  border-radius: 999px;
  background: linear-gradient(135deg, #ffb547, #ff8a3d);
  color: #1b1f4b;
  font-weight: 800;
  font-size: 14px;
}
.card:focus-visible,
.back:focus-visible {
  outline: 3px solid var(--gp-sun);
  outline-offset: 2px;
}
@media (min-width: 768px) and (min-height: 600px) {
  .head h1 {
    font-size: 28px;
  }
  .list {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }
  .card {
    min-height: 148px;
  }
  .card:last-child:nth-child(odd) {
    grid-column: 1 / -1;
    max-width: calc(50% - 8px);
    justify-self: center;
  }
}
@media (min-width: 1100px) {
  .list { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 20px; }
  .card { flex-direction: column; text-align: center; min-height: 240px; padding: 28px 20px; }
  .copy { flex: 1; align-items: center; }
  .card:last-child:nth-child(odd) { grid-column: auto; max-width: none; justify-self: stretch; }
}
@media (orientation: landscape) and (max-height: 520px) {
  .list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }
  .card {
    min-height: 96px;
    flex-direction: column;
    text-align: center;
    gap: 8px;
    padding: 14px 10px;
  }
  .name { font-size: 16px; }
  .emoji { font-size: 30px; }
  .card:last-child:nth-child(odd) { grid-column: auto; max-width: none; }
}
</style>

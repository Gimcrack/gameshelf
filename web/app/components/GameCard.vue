<script setup lang="ts">
import {
  formatPlaytime,
  hasDisconnectedPlatform,
  type LibraryEntry
} from '../utils/library'

const props = defineProps<{ entry: LibraryEntry }>()

const disconnected = computed(() => hasDisconnectedPlatform(props.entry))
const playtimeLabel = computed(() => formatPlaytime(props.entry.total_playtime_minutes))
</script>

<template>
  <article class="game-card" :class="{ disconnected }">
    <div class="cover">
      <img v-if="entry.cover_url" :src="entry.cover_url" :alt="entry.title" loading="lazy" />
      <div v-else class="cover-placeholder">{{ entry.title }}</div>
    </div>
    <div class="meta">
      <h3 class="title">{{ entry.title }}</h3>
      <p class="playtime">{{ playtimeLabel }}</p>
      <ul class="platforms">
        <li
          v-for="p in entry.platforms"
          :key="p.platform"
          class="platform-chip"
          :class="{ 'platform-disconnected': p.connection_status === 'disconnected' }"
        >
          {{ p.platform }}<span v-if="p.connection_status === 'disconnected'"> · disconnected</span>
        </li>
      </ul>
      <p v-if="entry.genres.length" class="genres">{{ entry.genres.join(', ') }}</p>
    </div>
  </article>
</template>

<style scoped>
.game-card {
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  overflow: hidden;
  background: #fff;
  display: flex;
  flex-direction: column;
}

.game-card.disconnected {
  opacity: 0.75;
}

.cover img {
  width: 100%;
  aspect-ratio: 3 / 4;
  object-fit: cover;
  display: block;
}

.cover-placeholder {
  aspect-ratio: 3 / 4;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem;
  text-align: center;
  background: #f0f0f0;
  color: #666;
  font-size: 0.85rem;
}

.meta {
  padding: 0.6rem 0.75rem 0.75rem;
}

.title {
  margin: 0 0 0.25rem;
  font-size: 0.95rem;
  line-height: 1.25;
}

.playtime {
  margin: 0 0 0.4rem;
  font-size: 0.8rem;
  color: #555;
}

.platforms {
  list-style: none;
  display: flex;
  flex-wrap: wrap;
  gap: 0.3rem;
  margin: 0 0 0.35rem;
  padding: 0;
}

.platform-chip {
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  background: #eef2f7;
  color: #33506b;
  border-radius: 4px;
  padding: 0.15rem 0.4rem;
}

.platform-disconnected {
  background: #fdeaea;
  color: #8a3535;
}

.genres {
  margin: 0;
  font-size: 0.75rem;
  color: #777;
}
</style>

<script setup lang="ts">
const { user, logout, fetchUser } = useAuth()
const isLoggingOut = ref(false)

onMounted(async () => {
  if (!user.value) {
    await fetchUser()
  }
})

async function onLogout(): Promise<void> {
  isLoggingOut.value = true
  try {
    await logout()
  } finally {
    isLoggingOut.value = false
    await navigateTo('/login')
  }
}
</script>

<template>
  <main class="library">
    <header class="library-header">
      <h1>GameShelf</h1>
      <div v-if="user" class="user-bar">
        <span class="user-email">{{ user.email }}</span>
        <button :disabled="isLoggingOut" @click="onLogout">
          {{ isLoggingOut ? 'Logging out…' : 'Log out' }}
        </button>
      </div>
    </header>
    <section class="library-body">
      <p>Your library is empty. Add your first game to get started.</p>
    </section>
  </main>
</template>

<style scoped>
.library {
  max-width: 640px;
  margin: 2rem auto;
  padding: 1.5rem;
  font-family: system-ui, sans-serif;
}

.library-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #ddd;
  padding-bottom: 1rem;
  margin-bottom: 1.5rem;
}

.user-bar {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-email {
  font-size: 0.9rem;
  color: #555;
}

button {
  padding: 0.4rem 0.8rem;
  cursor: pointer;
}

.library-body {
  color: #555;
}
</style>

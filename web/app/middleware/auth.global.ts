import { defineNuxtRouteMiddleware, navigateTo } from '#app'
import { useAuthToken, restoreTokenFromStorage } from '../utils/authState'

const PUBLIC_ROUTES = new Set(['/welcome', '/login', '/register'])

export default defineNuxtRouteMiddleware((to) => {
  restoreTokenFromStorage()

  const token = useAuthToken()
  const isPublicRoute = PUBLIC_ROUTES.has(to.path)

  if (!token.value && !isPublicRoute) {
    // Root is the shop window for guests; deep links still go to login.
    return navigateTo(to.path === '/' ? '/welcome' : '/login')
  }

  if (token.value && isPublicRoute) {
    return navigateTo('/')
  }
})

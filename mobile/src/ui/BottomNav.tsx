/** Bottom-nav — règle UX n°1 : actions principales en zone du pouce, 4-5 entrées max. */
import { NavLink } from 'react-router-dom'

export function BottomNav() {
  return (
    <nav className="bottom-nav">
      <NavLink to="/" end>
        <span className="nav-icon">🏠</span>
        <span>Accueil</span>
      </NavLink>
      <NavLink to="/mon-espace">
        <span className="nav-icon">👤</span>
        <span>Mon espace</span>
      </NavLink>
    </nav>
  )
}

import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider, useAuth } from './context/AuthContext';
import LoginPage from './pages/LoginPage';
import RegisterPage from './pages/RegisterPage';
import CampaignListPage from './pages/CampaignListPage';
import CreateCampaignPage from './pages/CreateCampaignPage';
import CampaignDetailPage from './pages/CampaignDetailPage';
import CreateCharacterPage from './pages/CreateCharacterPage';
import GamePlayPage from './pages/GamePlayPage';
import './App.css';

function ProtectedRoute({ children }: { children: React.ReactNode }) {
  const { user, loading } = useAuth();
  if (loading) return <div className="page"><p>Loading...</p></div>;
  if (!user) return <Navigate to="/login" />;
  return <>{children}</>;
}

function AppRoutes() {
  const { user, loading } = useAuth();
  if (loading) return <div className="page"><p>Loading...</p></div>;

  return (
    <Routes>
      <Route path="/login" element={user ? <Navigate to="/campaigns" /> : <LoginPage />} />
      <Route path="/register" element={user ? <Navigate to="/campaigns" /> : <RegisterPage />} />
      <Route path="/campaigns" element={<ProtectedRoute><CampaignListPage /></ProtectedRoute>} />
      <Route path="/campaigns/new" element={<ProtectedRoute><CreateCampaignPage /></ProtectedRoute>} />
      <Route path="/campaigns/:id" element={<ProtectedRoute><CampaignDetailPage /></ProtectedRoute>} />
      <Route path="/campaigns/:id/character/new" element={<ProtectedRoute><CreateCharacterPage /></ProtectedRoute>} />
      <Route path="/campaigns/:id/play" element={<ProtectedRoute><GamePlayPage /></ProtectedRoute>} />
      <Route path="*" element={<Navigate to={user ? '/campaigns' : '/login'} />} />
    </Routes>
  );
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <AppRoutes />
      </AuthProvider>
    </BrowserRouter>
  );
}

import { useEffect, useState } from 'react'
import { api } from './api/client'
import './App.css'

interface PingResponse {
  status: string
  app: string
  ai_provider: string
}

function App() {
  const [apiStatus, setApiStatus] = useState<PingResponse | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api.get<PingResponse>('/ping')
      .then(setApiStatus)
      .catch((err) => setError(err.message))
  }, [])

  return (
    <div className="app">
      <h1>DnD Extreme</h1>
      <p>AI-Driven Dungeon Master</p>

      <div className="status-card">
        {error && <p className="error">API: {error}</p>}
        {apiStatus && (
          <>
            <p>API: {apiStatus.status}</p>
            <p>AI Provider: {apiStatus.ai_provider}</p>
          </>
        )}
        {!apiStatus && !error && <p>Connecting to API...</p>}
      </div>
    </div>
  )
}

export default App

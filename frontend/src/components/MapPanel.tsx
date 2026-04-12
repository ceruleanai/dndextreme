import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Stage, Layer, Image as KonvaImage, Circle, Text } from 'react-konva';
import { MapMetadata, Combatant } from '../api/types';

interface Props {
  campaignId: string;
  mapData: MapMetadata | null;
  combatants?: Combatant[];
  combatActive?: boolean;
  currentTurn?: number;
}

export default function MapPanel({ campaignId, mapData, combatants, combatActive, currentTurn }: Props) {
  const [expanded, setExpanded] = useState(false);
  const [image, setImage] = useState<HTMLImageElement | null>(null);
  const [stageSize, setStageSize] = useState({ width: 600, height: 400 });
  const [scale, setScale] = useState(1);
  const [position, setPosition] = useState({ x: 0, y: 0 });
  const [fullscreen, setFullscreen] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  // Auto-expand when we have a map
  useEffect(() => {
    if (mapData) setExpanded(true);
  }, [mapData?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  // Auto-expand on combat
  useEffect(() => {
    if (combatActive) setExpanded(true);
  }, [combatActive]);

  // Load image when map changes
  useEffect(() => {
    if (!mapData) { setImage(null); return; }

    const img = new window.Image();
    img.crossOrigin = 'anonymous';
    img.src = mapData.image_path;
    img.onload = () => {
      setImage(img);
      setScale(1);
      setPosition({ x: 0, y: 0 });
    };
    img.onerror = () => setImage(null);
  }, [mapData?.image_path]); // eslint-disable-line react-hooks/exhaustive-deps

  // Measure container
  useEffect(() => {
    const measure = () => {
      if (containerRef.current) {
        const rect = containerRef.current.getBoundingClientRect();
        setStageSize({ width: rect.width, height: fullscreen ? window.innerHeight - 60 : 300 });
      }
    };
    measure();
    window.addEventListener('resize', measure);
    return () => window.removeEventListener('resize', measure);
  }, [expanded, fullscreen]);

  const handleWheel = useCallback((e: any) => {
    e.evt.preventDefault();
    const scaleBy = 1.1;
    const newScale = e.evt.deltaY < 0 ? scale * scaleBy : scale / scaleBy;
    setScale(Math.max(0.3, Math.min(5, newScale)));
  }, [scale]);

  if (!mapData && !combatActive) return null;

  // Compute image dimensions to fit
  let imgWidth = stageSize.width;
  let imgHeight = stageSize.height;
  if (image) {
    const ratio = Math.min(stageSize.width / image.width, stageSize.height / image.height);
    imgWidth = image.width * ratio;
    imgHeight = image.height * ratio;
  }

  // Token positions — arrange in a line if no positions stored
  const tokenRadius = 16;
  const tokens = combatActive && combatants ? combatants : [];

  return (
    <div className={`map-panel ${fullscreen ? 'map-fullscreen' : ''}`}>
      <div className="map-header" onClick={() => !fullscreen && setExpanded(!expanded)}>
        <span className="map-location-name">
          {mapData?.location_name || (combatActive ? 'Battle' : 'Map')}
        </span>
        <div className="map-controls">
          {expanded && (
            <button
              className="map-btn"
              onClick={(e) => { e.stopPropagation(); setFullscreen(!fullscreen); }}
              title={fullscreen ? 'Exit fullscreen' : 'Fullscreen'}
            >
              {fullscreen ? '\u2716' : '\u26F6'}
            </button>
          )}
          <span className="map-toggle">{expanded ? '\u25B2' : '\u25BC'}</span>
        </div>
      </div>

      {expanded && (
        <div className="map-canvas-container" ref={containerRef}>
          {image ? (
            <Stage
              width={stageSize.width}
              height={stageSize.height}
              scaleX={scale}
              scaleY={scale}
              x={position.x}
              y={position.y}
              draggable
              onDragEnd={(e) => setPosition({ x: e.target.x(), y: e.target.y() })}
              onWheel={handleWheel}
            >
              <Layer>
                {/* Map image */}
                <KonvaImage
                  image={image}
                  x={(stageSize.width / scale - imgWidth) / 2}
                  y={(stageSize.height / scale - imgHeight) / 2}
                  width={imgWidth}
                  height={imgHeight}
                />

                {/* Combat tokens */}
                {tokens.map((c, i) => {
                  const isDead = c.hp <= 0;
                  const isCurrent = i === currentTurn;
                  const tokenX = (stageSize.width / scale) * 0.15 + (i % 6) * 50;
                  const tokenY = (stageSize.height / scale) * 0.7 + Math.floor(i / 6) * 50;

                  return (
                    <React.Fragment key={i}>
                      {/* Selection ring */}
                      {isCurrent && (
                        <Circle
                          x={tokenX}
                          y={tokenY}
                          radius={tokenRadius + 4}
                          stroke="#facc15"
                          strokeWidth={2}
                        />
                      )}
                      <Circle
                        x={tokenX}
                        y={tokenY}
                        radius={tokenRadius}
                        fill={isDead ? '#666' : c.is_player ? '#4ade80' : '#ef4444'}
                        stroke={isCurrent ? '#facc15' : '#fff'}
                        strokeWidth={1}
                        opacity={isDead ? 0.4 : 1}
                        draggable
                      />
                      <Text
                        x={tokenX - tokenRadius}
                        y={tokenY - 6}
                        width={tokenRadius * 2}
                        text={c.name.charAt(0).toUpperCase()}
                        fontSize={14}
                        fontStyle="bold"
                        fill="white"
                        align="center"
                      />
                      <Text
                        x={tokenX - 20}
                        y={tokenY + tokenRadius + 2}
                        width={40}
                        text={c.name.split(' ')[0]}
                        fontSize={9}
                        fill="white"
                        align="center"
                      />
                    </React.Fragment>
                  );
                })}
              </Layer>
            </Stage>
          ) : (
            <div className="map-placeholder">
              <p>No map image available</p>
            </div>
          )}
        </div>
      )}
    </div>
  );
}


import React, {
  useState,
  useEffect,
  useMemo,
  useCallback,
  useRef,
} from "react";
import { useParams, useNavigate } from "react-router-dom";
import {
  Container,
  Box,
  Typography,
  Grid,
  Paper,
  Chip,
  Button,
  Tabs,
  Tab,
  Card,
  CardContent,
  LinearProgress,
  Divider,
  Stack,
  IconButton,
  Alert,
  CircularProgress,
  Tooltip,
  alpha,
  useTheme,
  Fade,
  Zoom,
  Avatar,
  Skeleton,
  CardActionArea,
  useMediaQuery,
  Snackbar,
  Alert as MuiAlert,
} from "@mui/material";
import {
  ArrowBack,
  TrendingUp,
  AttachMoney,
  Sports,
  ShowChart,
  Security,
  Timeline,
  BarChart,
  PieChart,
  History,
  Share,
  Download,
  Refresh,
  Warning,
  CalendarToday,
  AccountBalanceWallet,
  CompareArrows,
  Psychology,
  QueryStats,
  EmojiEvents,
  ChevronRight,
  AutoGraph,
  Score,
  SportsSoccer,
  Event,
  OpenInNew,
  HourglassEmpty,
  CheckCircle,
  Error,
  PlayArrow,
  Info,
} from "@mui/icons-material";
import { keyframes } from "@mui/system";
import slipApi from "../services/api/slipApi";

// ======================
// DARK THEME DESIGN SYSTEM
// ======================
const DARK_THEME = {
  palette: {
    background: {
      primary: "#0f1215",
      surface1: "#161b22",
      surface2: "#1d2229",
      surface3: "#242a33",
      overlay: "rgba(22, 27, 34, 0.8)",
    },
    text: {
      primary: "#ffffff",
      secondary: "#a0aec0",
      tertiary: "#718096",
      accent: "#38bdf8",
    },
    status: {
      draft: "#38bdf8",
      active: "#10b981",
      won: "#10b981",
      lost: "#ef4444",
      pending: "#f59e0b",
    },
    accents: {
      primary: "#6366f1",
      secondary: "#10b981",
      tertiary: "#8b5cf6",
      highlight: "#f59e0b",
    },
    gradients: {
      mlButton:
        "linear-gradient(135deg, #8b5cf6 0%, #6366f1 50%, #3b82f6 100%)",
      success: "linear-gradient(135deg, #10b981 0%, #34d399 100%)",
      warning: "linear-gradient(135deg, #f59e0b 0%, #fbbf24 100%)",
      danger: "linear-gradient(135deg, #ef4444 0%, #f87171 100%)",
      surface: "linear-gradient(180deg, #1d2229 0%, #161b22 100%)",
    },
  },
  shadows: {
    surface1:
      "0px 2px 8px rgba(0, 0, 0, 0.24), 0px 1px 4px rgba(0, 0, 0, 0.16)",
    surface2:
      "0px 4px 16px rgba(0, 0, 0, 0.32), 0px 2px 8px rgba(0, 0, 0, 0.24)",
    surface3:
      "0px 8px 32px rgba(0, 0, 0, 0.4), 0px 4px 16px rgba(0, 0, 0, 0.32)",
    glow: "0 0 20px rgba(139, 92, 246, 0.3), 0 0 40px rgba(99, 102, 241, 0.2)",
    inner: "inset 0 2px 4px rgba(0, 0, 0, 0.5)",
  },
  typography: {
    fontFamily:
      "'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif",
    h1: {
      fontSize: "2.5rem",
      fontWeight: 700,
      lineHeight: 1.2,
      letterSpacing: "-0.02em",
    },
    h2: {
      fontSize: "2rem",
      fontWeight: 700,
      lineHeight: 1.25,
      letterSpacing: "-0.01em",
    },
    h3: { fontSize: "1.75rem", fontWeight: 600, lineHeight: 1.3 },
    h4: { fontSize: "1.5rem", fontWeight: 600, lineHeight: 1.35 },
    h5: { fontSize: "1.25rem", fontWeight: 600, lineHeight: 1.4 },
    h6: { fontSize: "1rem", fontWeight: 600, lineHeight: 1.5 },
    body1: { fontSize: "1rem", fontWeight: 400, lineHeight: 1.6 },
    body2: { fontSize: "0.875rem", fontWeight: 400, lineHeight: 1.57 },
    caption: { fontSize: "0.75rem", fontWeight: 400, lineHeight: 1.5 },
  },
  shape: {
    borderRadius: {
      xs: "4px",
      sm: "8px",
      md: "12px",
      lg: "16px",
      xl: "20px",
      "2xl": "24px",
      pill: "9999px",
      circle: "50%",
    },
  },
  spacing: (factor) => `${0.5 * factor}rem`,
};

// ======================
// ANIMATIONS
// ======================
const pulseGlow = keyframes`
  0% {
    box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.7);
  }
  70% {
    box-shadow: 0 0 0 15px rgba(99, 102, 241, 0);
  }
  100% {
    box-shadow: 0 0 0 0 rgba(99, 102, 241, 0);
  }
`;

const spinSlow = keyframes`
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
`;

const fadeInUp = keyframes`
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
`;

const shimmer = keyframes`
  0% {
    background-position: -200% center;
  }
  100% {
    background-position: 200% center;
  }
`;

// ======================
// STATUS DISPLAY COMPONENT
// ======================
const AnalysisStatusDisplay = ({ status, mlRunning }) => {
  const getStatusConfig = (status) => {
    const configs = {
      queued: {
        label: "Queued",
        color: DARK_THEME.palette.status.pending,
        icon: <HourglassEmpty sx={{ fontSize: 16 }} />,
      },
      processing: {
        label: "Processing",
        color: DARK_THEME.palette.accents.primary,
        icon: <AutoGraph sx={{ fontSize: 16 }} />,
      },
      completed: {
        label: "Completed",
        color: DARK_THEME.palette.status.won,
        icon: <CheckCircle sx={{ fontSize: 16 }} />,
      },
      failed: {
        label: "Failed",
        color: DARK_THEME.palette.status.lost,
        icon: <Error sx={{ fontSize: 16 }} />,
      },
      ready: {
        label: "Ready",
        color: DARK_THEME.palette.text.secondary,
        icon: <PlayArrow sx={{ fontSize: 16 }} />,
      },
    };

    return configs[status] || configs.ready;
  };

  const config = getStatusConfig(status);

  return (
    <Box
      sx={{
        display: "flex",
        alignItems: "center",
        gap: 1,
        position: "absolute",
        top: 16,
        right: 16,
        zIndex: 2,
      }}
    >
      {mlRunning && <CircularProgress size={16} sx={{ color: config.color }} />}
      <Chip
        icon={config.icon}
        label={config.label}
        size="small"
        sx={{
          bgcolor: alpha(config.color, 0.1),
          color: config.color,
          fontWeight: 600,
          fontSize: "0.75rem",
          border: `1px solid ${alpha(config.color, 0.3)}`,
          backdropFilter: "blur(8px)",
        }}
      />
    </Box>
  );
};

// ======================
// ML GENERATION BUTTON
// ======================
const MLGenerationButton = ({ onRun, loading, status }) => {
  const [isHovered, setIsHovered] = useState(false);

  const getButtonTooltip = () => {
    if (loading) return "Analysis in progress...";
    if (status === "processing") return "Analysis already running";
    return "Generate optimized slip using machine learning";
  };

  return (
    <Tooltip title={getButtonTooltip()} arrow placement="top">
      <Box
        sx={{
          position: "relative",
          display: "flex",
          justifyContent: "center",
          my: 4,
        }}
      >
        <AnalysisStatusDisplay status={status} mlRunning={loading} />







        

        <Box
          sx={{
            position: "absolute",
            top: "50%",
            left: "50%",
            transform: "translate(-50%, -50%)",
            width: 240,
            height: 240,
            borderRadius: "50%",
            background: DARK_THEME.palette.gradients.mlButton,
            filter: "blur(40px)",
            opacity: isHovered ? 0.25 : 0.2,
            animation: `${pulseGlow} 3s infinite`,
            transition: "opacity 0.3s ease",
          }}
        />

        <Button
          onClick={onRun}
          onMouseEnter={() => setIsHovered(true)}
          onMouseLeave={() => setIsHovered(false)}
          disabled={loading || status === "processing"}
          sx={{
            width: 160,
            height: 160,
            borderRadius: "50%",
            background: DARK_THEME.palette.gradients.mlButton,
            color: "white",
            fontWeight: 700,
            fontSize: "1.1rem",
            letterSpacing: "0.05em",
            textTransform: "uppercase",
            border: `3px solid ${alpha("#ffffff", 0.2)}`,
            boxShadow: `
              0 0 40px rgba(139, 92, 246, 0.4),
              inset 0 0 20px rgba(255, 255, 255, 0.1)
            `,
            position: "relative",
            overflow: "hidden",
            animation: loading ? `${spinSlow} 3s linear infinite` : "none",
            transition: "all 0.4s cubic-bezier(0.4, 0, 0.2, 1)",
            "&:hover": {
              transform: status !== "processing" ? "scale(1.05)" : "none",
              boxShadow:
                status !== "processing"
                  ? `
                0 0 60px rgba(139, 92, 246, 0.6),
                inset 0 0 30px rgba(255, 255, 255, 0.15)
              `
                  : null,
              "& .button-content": {
                transform:
                  status !== "processing" ? "translateY(-2px)" : "none",
              },
              "& .button-icon": {
                transform: status !== "processing" ? "scale(1.1)" : "none",
              },
            },
            "&:active": {
              transform: status !== "processing" ? "scale(0.98)" : "none",
            },
            "&::before": {
              content: '""',
              position: "absolute",
              top: 0,
              left: 0,
              right: 0,
              bottom: 0,
              background:
                "linear-gradient(45deg, transparent 30%, rgba(255,255,255,0.1) 50%, transparent 70%)",
              animation: loading ? `${shimmer} 3s infinite linear` : "none",
            },
            opacity: status === "processing" ? 0.7 : 1,
            cursor: status === "processing" ? "not-allowed" : "pointer",
          }}
        >
          <Stack
            spacing={1}
            alignItems="center"
            className="button-content"
            sx={{
              transition: "transform 0.3s ease",
            }}
          >
            <AutoGraph
              className="button-icon"
              sx={{
                fontSize: 36,
                mb: 1,
                transition: "transform 0.3s ease",
              }}
            />
            <Box sx={{ textAlign: "center" }}>
              <Typography
                variant="button"
                sx={{
                  fontWeight: 700,
                  fontSize: "0.9rem",
                  letterSpacing: "0.1em",
                  display: "block",
                }}
              >
                Run ML
              </Typography>
              <Typography
                variant="caption"
                sx={{
                  fontWeight: 600,
                  fontSize: "0.75rem",
                  letterSpacing: "0.05em",
                  opacity: 0.9,
                  display: "block",
                }}
              >
                Slip Generation
              </Typography>
            </Box>
          </Stack>
        </Button>
      </Box>
    </Tooltip>
  );
};

// ======================
// REUSABLE COMPONENTS
// ======================
const StatCard = ({
  title,
  value,
  subtitle,
  icon: Icon,
  color,
  trend,
  size = "medium",
}) => (
  <Zoom in={true}>
    <Card
      sx={{
        height: "100%",
        borderRadius: DARK_THEME.shape.borderRadius.lg,
        backgroundColor: DARK_THEME.palette.background.surface2,
        border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
        transition: "all 0.3s cubic-bezier(0.4, 0, 0.2, 1)",
        "&:hover": {
          transform: "translateY(-2px)",
          borderColor: alpha(color || DARK_THEME.palette.accents.primary, 0.3),
          boxShadow: DARK_THEME.shadows.surface2,
        },
      }}
    >
      <CardContent sx={{ p: 3 }}>
        <Stack spacing={2}>
          <Stack direction="row" alignItems="center" spacing={2}>
            <Box
              sx={{
                width: 40,
                height: 40,
                borderRadius: DARK_THEME.shape.borderRadius.md,
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                backgroundColor: alpha(
                  color || DARK_THEME.palette.accents.primary,
                  0.1
                ),
                color: color || DARK_THEME.palette.accents.primary,
              }}
            >
              <Icon sx={{ fontSize: 20 }} />
            </Box>
            <Typography
              variant="subtitle2"
              sx={{
                color: DARK_THEME.palette.text.secondary,
                fontWeight: 500,
                textTransform: "uppercase",
                fontSize: "0.75rem",
                letterSpacing: "0.05em",
              }}
            >
              {title}
            </Typography>
          </Stack>
          <Typography
            variant={size === "large" ? "h3" : "h4"}
            sx={{
              fontWeight: 700,
              color: DARK_THEME.palette.text.primary,
              lineHeight: 1,
            }}
          >
            {value}
          </Typography>
          {subtitle && (
            <Typography
              variant="body2"
              sx={{
                color: DARK_THEME.palette.text.tertiary,
                mt: 1,
              }}
            >
              {subtitle}
            </Typography>
          )}
        </Stack>
      </CardContent>
    </Card>
  </Zoom>
);

const MatchCard = ({ match, index }) => {
  const navigate = useNavigate();

  const handleMatchClick = () => {
    navigate(`/matches/${match.match_id}`);
  };

  const getMarketIcon = (market) => {
    switch (market) {
      case "1X2":
        return <Score sx={{ fontSize: 16 }} />;
      case "Over/Under 2.5":
        return <TrendingUp sx={{ fontSize: 16 }} />;
      case "BTTS":
        return <SportsSoccer sx={{ fontSize: 16 }} />;
      default:
        return <Event sx={{ fontSize: 16 }} />;
    }
  };

  return (
    <Fade in={true} timeout={300 + index * 100}>
      <Card
        sx={{
          borderRadius: DARK_THEME.shape.borderRadius.lg,
          backgroundColor: DARK_THEME.palette.background.surface2,
          border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
          transition: "all 0.2s ease",
          "&:hover": {
            borderColor: alpha(DARK_THEME.palette.accents.primary, 0.3),
            boxShadow: DARK_THEME.shadows.surface2,
            transform: "translateY(-1px)",
          },
        }}
      >
        <CardContent sx={{ p: 2.5 }}>
          <Stack spacing={2}>
            {/* Match Header */}
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="center"
            >
              <Stack direction="row" spacing={1} alignItems="center">
                <Avatar
                  sx={{
                    width: 28,
                    height: 28,
                    bgcolor: alpha(DARK_THEME.palette.accents.primary, 0.1),
                    color: DARK_THEME.palette.accents.primary,
                  }}
                >
                  <SportsSoccer sx={{ fontSize: 16 }} />
                </Avatar>
                <Typography
                  variant="caption"
                  sx={{
                    color: DARK_THEME.palette.text.tertiary,
                    fontWeight: 500,
                    fontSize: "0.75rem",
                  }}
                >
                  {match.league}
                </Typography>
              </Stack>
              <Stack direction="row" spacing={1} alignItems="center">
                <Tooltip title={match.market} arrow>
                  <Box
                    sx={{
                      display: "flex",
                      alignItems: "center",
                      gap: 0.5,
                      px: 1,
                      py: 0.5,
                      borderRadius: DARK_THEME.shape.borderRadius.sm,
                      bgcolor: alpha(DARK_THEME.palette.accents.primary, 0.1),
                    }}
                  >
                    {getMarketIcon(match.market)}
                    <Typography
                      variant="caption"
                      sx={{
                        color: DARK_THEME.palette.text.secondary,
                        fontWeight: 600,
                        fontSize: "0.7rem",
                      }}
                    >
                      {match.market === "Over/Under 2.5" ? "O/U" : match.market}
                    </Typography>
                  </Box>
                </Tooltip>
                <Tooltip title="View match details">
                  <IconButton
                    size="small"
                    onClick={(e) => {
                      e.stopPropagation();
                      handleMatchClick();
                    }}
                    sx={{
                      color: DARK_THEME.palette.text.tertiary,
                      "&:hover": {
                        color: DARK_THEME.palette.text.accent,
                        backgroundColor: alpha(
                          DARK_THEME.palette.background.surface3,
                          0.3
                        ),
                      },
                    }}
                  >
                    <OpenInNew sx={{ fontSize: 16 }} />
                  </IconButton>
                </Tooltip>
              </Stack>
            </Stack>

            {/* Teams & Odds */}
            <Box
              onClick={handleMatchClick}
              sx={{
                cursor: "pointer",
                "&:hover": {
                  opacity: 0.9,
                },
              }}
            >
              <Stack
                direction="row"
                alignItems="center"
                justifyContent="space-between"
                spacing={2}
              >
                <Stack alignItems="center" sx={{ flex: 1 }}>
                  <Typography
                    variant="subtitle2"
                    sx={{
                      color: DARK_THEME.palette.text.primary,
                      fontWeight: 600,
                      textAlign: "center",
                      mb: 0.5,
                    }}
                  >
                    {match.home_team}
                  </Typography>
                  <Typography
                    variant="caption"
                    sx={{
                      color: DARK_THEME.palette.text.tertiary,
                    }}
                  >
                    Home
                  </Typography>
                </Stack>

                <Stack alignItems="center" spacing={0.5}>
                  <Typography
                    variant="caption"
                    sx={{
                      color: DARK_THEME.palette.text.tertiary,
                      fontWeight: 600,
                    }}
                  >
                    VS
                  </Typography>
                  <Chip
                    label={match.odds.toFixed(2)}
                    size="small"
                    sx={{
                      fontWeight: 700,
                      bgcolor: alpha(DARK_THEME.palette.status.draft, 0.1),
                      color: DARK_THEME.palette.status.draft,
                      border: `1px solid ${alpha(DARK_THEME.palette.status.draft, 0.3)}`,
                      fontSize: "0.8rem",
                      height: 24,
                    }}
                  />
                  <Typography
                    variant="caption"
                    sx={{
                      color: DARK_THEME.palette.text.tertiary,
                      fontSize: "0.65rem",
                    }}
                  >
                    {match.selection}
                  </Typography>
                </Stack>

                <Stack alignItems="center" sx={{ flex: 1 }}>
                  <Typography
                    variant="subtitle2"
                    sx={{
                      color: DARK_THEME.palette.text.primary,
                      fontWeight: 600,
                      textAlign: "center",
                      mb: 0.5,
                    }}
                  >
                    {match.away_team}
                  </Typography>
                  <Typography
                    variant="caption"
                    sx={{
                      color: DARK_THEME.palette.text.tertiary,
                    }}
                  >
                    Away
                  </Typography>
                </Stack>
              </Stack>
            </Box>

            {/* Match Footer */}
            <Stack
              direction="row"
              justifyContent="space-between"
              alignItems="center"
              sx={{
                pt: 1,
                borderTop: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.3)}`,
              }}
            >
              <Stack direction="row" spacing={0.5} alignItems="center">
                <CalendarToday
                  sx={{ fontSize: 14, color: DARK_THEME.palette.text.tertiary }}
                />
                <Typography
                  variant="caption"
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                  }}
                >
                  {new Date(match.match_date).toLocaleDateString("en-US", {
                    weekday: "short",
                    month: "short",
                    day: "numeric",
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                </Typography>
              </Stack>
              <ChevronRight
                sx={{
                  fontSize: 16,
                  color: DARK_THEME.palette.text.tertiary,
                }}
              />
            </Stack>
          </Stack>
        </CardContent>
      </Card>
    </Fade>
  );
};

// ======================
// MAIN COMPONENT
// ======================
const MasterSlipAnalysisPage = () => {
  const { id } = useParams();
  const navigate = useNavigate();
  const theme = useTheme();
  const isMobile = useMediaQuery("(max-width: 600px)");

  const [loading, setLoading] = useState(true);
  const [slip, setSlip] = useState(null);
  const [error, setError] = useState(null);
  const [activeTab, setActiveTab] = useState(0);
  const [refreshing, setRefreshing] = useState(false);
  const [mlRunning, setMlRunning] = useState(false);
  const [analysisStatus, setAnalysisStatus] = useState("ready");
  const [insights, setInsights] = useState(null);
  const [notification, setNotification] = useState(null);

  const pollIntervalRef = useRef(null);
  const timeoutRef = useRef(null);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
      }
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, []);

  // Enhanced data fetching with additional insights
  const fetchSlipData = useCallback(async () => {
    try {
      setLoading(true);

      // Fetch main slip data
      const response = await slipApi.getSlipForDashboard(id);
      const data = response.data?.data || response.data;

      if (data) {
        setSlip(data);
        setError(null);

        // Update analysis status from slip data
        if (data.engine_status) {
          setAnalysisStatus(data.engine_status);
        }

        // Try to fetch insights if available
        try {
          const insightsResponse = await slipApi.getSlipInsights(id);
          setInsights(insightsResponse.data?.data || null);
        } catch (insightsError) {
          console.warn("Could not load additional insights:", insightsError);
          // Insights are optional
        }
      }
    } catch (err) {
      console.error("Error fetching slip analysis:", err);
      setError("Failed to load slip analysis. Please try again.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [id]);

  useEffect(() => {
    fetchSlipData();
  }, [fetchSlipData]);

  // ML Generation Handler with Polling
  const handleRunMLGeneration = async () => {
    setMlRunning(true);
    setAnalysisStatus("queued");

    try {
      // Step 1: Start the analysis
      const startResponse = await slipApi.runSlipAnalysis(id);
      console.log("Analysis started:", startResponse);

      // Update slip data immediately
      await fetchSlipData();

      // Step 2: Start polling for status
      pollIntervalRef.current = setInterval(async () => {
        try {
          const statusResponse = await slipApi.checkAnalysisStatus(id);
          const {
            status,
            engine_status,
            analysis_quality,
            alternative_slips_count,
          } = statusResponse.data;

          // Update UI with current status
          setAnalysisStatus(engine_status);

          // Check if analysis is complete
          if (engine_status === "completed" || engine_status === "failed") {
            clearInterval(pollIntervalRef.current);
            setMlRunning(false);

            // Refresh slip data
            await fetchSlipData();

            if (engine_status === "completed") {
              console.log("ML slip generation completed successfully");

              // Show success notification
              if (alternative_slips_count > 0) {
                setNotification({
                  type: "success",
                  message: `Generated ${alternative_slips_count} optimized slip${alternative_slips_count > 1 ? "s" : ""}!`,
                });
              }
            } else {
              setError("ML generation failed. Please try again.");
            }
          }
        } catch (error) {
          console.error("Error polling status:", error);
          clearInterval(pollIntervalRef.current);
          setMlRunning(false);
          setAnalysisStatus("failed");
          setError("Failed to check analysis status");
        }
      }, 2000); // Poll every 2 seconds

      // Timeout after 90 seconds
      timeoutRef.current = setTimeout(() => {
        if (pollIntervalRef.current) {
          clearInterval(pollIntervalRef.current);
        }
        if (mlRunning) {
          setMlRunning(false);
          setAnalysisStatus("failed");
          setError("Analysis timed out. Please try again.");
        }
      }, 90000);
    } catch (err) {
      console.error("ML generation failed to start:", err);
      setMlRunning(false);
      setAnalysisStatus("failed");
      setError("Failed to start ML analysis. Please try again.");

      // Cleanup any existing intervals/timeouts
      if (pollIntervalRef.current) {
        clearInterval(pollIntervalRef.current);
      }
      if (timeoutRef.current) {
        clearTimeout(timeoutRef.current);
      }
    }
  };

  const handleRefresh = () => {
    setRefreshing(true);
    fetchSlipData();
  };

  const handleCloseNotification = () => {
    setNotification(null);
  };

  // Calculate analytics with enhanced metrics
  const analytics = useMemo(() => {
    if (!slip) return null;

    const matches = slip.matches || [];
    const stake = slip.stake || 0;
    const totalOdds = slip.total_odds || 1;

    const possibleReturn = slip.estimated_payout || stake * totalOdds;
    const potentialProfit = possibleReturn - stake;
    const roi = stake > 0 ? (potentialProfit / stake) * 100 : 0;

    // Calculate average odds
    const averageOdds =
      matches.length > 0
        ? matches.reduce(
            (sum, match) => sum + (parseFloat(match.odds) || 1),
            0
          ) / matches.length
        : 1;

    const marketDistribution = {};
    const leagueDistribution = {};

    matches.forEach((match) => {
      const market = match.market || "Unknown";
      const league = match.league || "Unknown";
      marketDistribution[market] = (marketDistribution[market] || 0) + 1;
      leagueDistribution[league] = (leagueDistribution[league] || 0) + 1;
    });

    return {
      stake,
      totalOdds,
      possibleReturn,
      potentialProfit,
      roi,
      matchesCount: slip.matches_count || matches.length,
      marketDistribution,
      leagueDistribution,
      averageOdds,
      status: slip.status,
      engine_status: slip.engine_status,
      currency: slip.currency || "USD",
      createdAt: slip.created_at,
      alternative_slips_count: slip.alternative_slips_count || 0,
    };
  }, [slip]);

  // Loading skeleton - existing code remains...
  if (loading) {
    return (
      <Box
        sx={{
          minHeight: "100vh",
          backgroundColor: DARK_THEME.palette.background.primary,
          py: 4,
        }}
      >
        <Container maxWidth="xl">
          <Stack spacing={4}>
            {/* Header skeleton */}
            <Skeleton
              variant="rounded"
              height={180}
              sx={{
                borderRadius: DARK_THEME.shape.borderRadius.xl,
                bgcolor: DARK_THEME.palette.background.surface2,
              }}
            />

            {/* Metrics skeleton */}
            <Grid container spacing={3}>
              {[1, 2, 3, 4].map((item) => (
                <Grid item xs={12} sm={6} md={3} key={item}>
                  <Skeleton
                    variant="rounded"
                    height={140}
                    sx={{
                      borderRadius: DARK_THEME.shape.borderRadius.lg,
                      bgcolor: DARK_THEME.palette.background.surface2,
                    }}
                  />
                </Grid>
              ))}
            </Grid>

            {/* ML Button skeleton */}
            <Box sx={{ display: "flex", justifyContent: "center", my: 4 }}>
              <Skeleton
                variant="circular"
                width={160}
                height={160}
                sx={{
                  bgcolor: DARK_THEME.palette.background.surface2,
                }}
              />
            </Box>

            {/* Content skeleton */}
            <Grid container spacing={3}>
              <Grid item xs={12} md={8}>
                <Skeleton
                  variant="rounded"
                  height={400}
                  sx={{
                    borderRadius: DARK_THEME.shape.borderRadius.lg,
                    bgcolor: DARK_THEME.palette.background.surface2,
                  }}
                />
              </Grid>
              <Grid item xs={12} md={4}>
                <Skeleton
                  variant="rounded"
                  height={400}
                  sx={{
                    borderRadius: DARK_THEME.shape.borderRadius.lg,
                    bgcolor: DARK_THEME.palette.background.surface2,
                  }}
                />
              </Grid>
            </Grid>
          </Stack>
        </Container>
      </Box>
    );
  }

  if (error || !slip) {
    return (
      <Box
        sx={{
          minHeight: "100vh",
          backgroundColor: DARK_THEME.palette.background.primary,
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          p: 3,
        }}
      >
        <Zoom in={true}>
          <Paper
            sx={{
              p: 4,
              borderRadius: DARK_THEME.shape.borderRadius.xl,
              backgroundColor: DARK_THEME.palette.background.surface2,
              border: `1px solid ${alpha(DARK_THEME.palette.status.lost, 0.2)}`,
              maxWidth: 500,
              width: "100%",
            }}
          >
            <Stack spacing={3} alignItems="center">
              <Warning
                sx={{
                  fontSize: 64,
                  color: DARK_THEME.palette.status.lost,
                }}
              />
              <Box sx={{ textAlign: "center" }}>
                <Typography
                  variant="h5"
                  sx={{
                    fontWeight: 700,
                    color: DARK_THEME.palette.text.primary,
                    mb: 1,
                  }}
                >
                  Analysis Unavailable
                </Typography>
                <Typography
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    mb: 3,
                  }}
                >
                  {error || "The requested slip analysis could not be loaded."}
                </Typography>
              </Box>
              <Stack direction="row" spacing={2}>
                <Button
                  startIcon={<ArrowBack />}
                  onClick={() => navigate(-1)}
                  variant="contained"
                  sx={{
                    borderRadius: DARK_THEME.shape.borderRadius.pill,
                    px: 3,
                    bgcolor: DARK_THEME.palette.background.surface3,
                    "&:hover": {
                      bgcolor: DARK_THEME.palette.background.surface2,
                    },
                  }}
                >
                  Back
                </Button>
                <Button
                  startIcon={<Refresh />}
                  onClick={fetchSlipData}
                  variant="outlined"
                  sx={{
                    borderRadius: DARK_THEME.shape.borderRadius.pill,
                    px: 3,
                    borderColor: DARK_THEME.palette.accents.primary,
                    color: DARK_THEME.palette.accents.primary,
                  }}
                >
                  Retry
                </Button>
              </Stack>
            </Stack>
          </Paper>
        </Zoom>
      </Box>
    );
  }

  const TabPanel = ({ children, value, index }) => (
    <Fade in={value === index} timeout={300}>
      <div hidden={value !== index} style={{ width: "100%" }}>
        {value === index && <Box sx={{ py: 3 }}>{children}</Box>}
      </div>
    </Fade>
  );

  return (
    <Box
      sx={{
        minHeight: "100vh",
        backgroundColor: DARK_THEME.palette.background.primary,
        py: 4,
      }}
    >
      {/* Notification Snackbar */}
      <Snackbar
        open={!!notification}
        autoHideDuration={6000}
        onClose={handleCloseNotification}
        anchorOrigin={{ vertical: "bottom", horizontal: "right" }}
      >
        <MuiAlert
          onClose={handleCloseNotification}
          severity={notification?.type || "success"}
          sx={{
            width: "100%",
            bgcolor:
              notification?.type === "success"
                ? DARK_THEME.palette.status.won
                : DARK_THEME.palette.status.lost,
            color: "white",
            "& .MuiAlert-icon": {
              color: "white",
            },
          }}
        >
          {notification?.message}
        </MuiAlert>
      </Snackbar>

      <Container maxWidth="xl">
        {/* Header Section */}
        <Stack spacing={3} sx={{ mb: 4 }}>
          <Stack
            direction="row"
            justifyContent="space-between"
            alignItems="center"
          >
            <Button
              startIcon={<ArrowBack />}
              onClick={() => navigate(-1)}
              sx={{
                color: DARK_THEME.palette.text.secondary,
                "&:hover": {
                  backgroundColor: alpha(
                    DARK_THEME.palette.background.surface2,
                    0.5
                  ),
                },
              }}
            >
              Back to Slips
            </Button>

            <Stack direction="row" spacing={1}>
              <Tooltip title="Refresh analysis">
                <IconButton
                  onClick={handleRefresh}
                  disabled={refreshing}
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    "&:hover": {
                      color: DARK_THEME.palette.text.primary,
                      backgroundColor: alpha(
                        DARK_THEME.palette.background.surface2,
                        0.5
                      ),
                    },
                  }}
                >
                  <Refresh />
                </IconButton>
              </Tooltip>
              <Tooltip title="Share analysis">
                <IconButton
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    "&:hover": {
                      color: DARK_THEME.palette.text.primary,
                      backgroundColor: alpha(
                        DARK_THEME.palette.background.surface2,
                        0.5
                      ),
                    },
                  }}
                >
                  <Share />
                </IconButton>
              </Tooltip>
              <Tooltip title="Export data">
                <IconButton
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    "&:hover": {
                      color: DARK_THEME.palette.text.primary,
                      backgroundColor: alpha(
                        DARK_THEME.palette.background.surface2,
                        0.5
                      ),
                    },
                  }}
                >
                  <Download />
                </IconButton>
              </Tooltip>
            </Stack>
          </Stack>

          {/* Main Header Card */}
          <Paper
            sx={{
              p: { xs: 3, md: 4 },
              borderRadius: DARK_THEME.shape.borderRadius.xl,
              backgroundColor: DARK_THEME.palette.background.surface1,
              border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
              animation: `${fadeInUp} 0.6s ease-out`,
              position: "relative",
              overflow: "hidden",
              "&::before": {
                content: '""',
                position: "absolute",
                top: 0,
                left: 0,
                right: 0,
                height: "3px",
                background: DARK_THEME.palette.gradients.mlButton,
              },
            }}
          >
            <Stack spacing={3}>
              <Stack
                direction={{ xs: "column", md: "row" }}
                justifyContent="space-between"
                alignItems={{ xs: "flex-start", md: "center" }}
                spacing={2}
              >
                <Box>
                  <Typography
                    variant="h1"
                    sx={{
                      ...DARK_THEME.typography.h1,
                      color: DARK_THEME.palette.text.primary,
                      mb: 1,
                    }}
                  >
                    {slip.name}
                  </Typography>
                  <Stack direction="row" spacing={1} alignItems="center">
                    <Chip
                      label={`ID: ${id}`}
                      size="small"
                      sx={{
                        bgcolor: alpha(
                          DARK_THEME.palette.background.surface3,
                          0.5
                        ),
                        color: DARK_THEME.palette.text.secondary,
                        fontWeight: 600,
                        fontSize: "0.75rem",
                        height: 24,
                      }}
                    />
                    <Chip
                      label={
                        (slip.status || "draft").charAt(0).toUpperCase() +
                        (slip.status || "draft").slice(1)
                      }
                      size="small"
                      sx={{
                        bgcolor: alpha(
                          DARK_THEME.palette.status[slip.status] ||
                            DARK_THEME.palette.status.draft,
                          0.1
                        ),
                        color:
                          DARK_THEME.palette.status[slip.status] ||
                          DARK_THEME.palette.status.draft,
                        border: `1px solid ${alpha(DARK_THEME.palette.status[slip.status] || DARK_THEME.palette.status.draft, 0.3)}`,
                        fontWeight: 700,
                        fontSize: "0.75rem",
                        height: 24,
                      }}
                    />
                  </Stack>
                </Box>

                <Button
                  variant="contained"
                  startIcon={<EmojiEvents />}
                  sx={{
                    bgcolor: DARK_THEME.palette.accents.primary,
                    color: "white",
                    fontWeight: 700,
                    px: 4,
                    py: 1.5,
                    borderRadius: DARK_THEME.shape.borderRadius.pill,
                    "&:hover": {
                      bgcolor: alpha(DARK_THEME.palette.accents.primary, 0.9),
                      transform: "translateY(-1px)",
                    },
                    transition: "all 0.2s ease",
                  }}
                >
                  Place Bet
                </Button>
              </Stack>

              <Grid container spacing={2}>
                <Grid item xs={6} sm={3}>
                  <Box>
                    <Typography
                      variant="caption"
                      sx={{
                        color: DARK_THEME.palette.text.tertiary,
                        fontWeight: 500,
                        fontSize: "0.75rem",
                        display: "block",
                        mb: 0.5,
                      }}
                    >
                      Created
                    </Typography>
                    <Typography
                      variant="body2"
                      sx={{
                        color: DARK_THEME.palette.text.primary,
                        fontWeight: 600,
                      }}
                    >
                      {new Date(slip.created_at).toLocaleDateString()}
                    </Typography>
                  </Box>
                </Grid>
                <Grid item xs={6} sm={3}>
                  <Box>
                    <Typography
                      variant="caption"
                      sx={{
                        color: DARK_THEME.palette.text.tertiary,
                        fontWeight: 500,
                        fontSize: "0.75rem",
                        display: "block",
                        mb: 0.5,
                      }}
                    >
                      Matches
                    </Typography>
                    <Typography
                      variant="body2"
                      sx={{
                        color: DARK_THEME.palette.text.primary,
                        fontWeight: 600,
                      }}
                    >
                      {analytics.matchesCount}
                    </Typography>
                  </Box>
                </Grid>
                <Grid item xs={6} sm={3}>
                  <Box>
                    <Typography
                      variant="caption"
                      sx={{
                        color: DARK_THEME.palette.text.tertiary,
                        fontWeight: 500,
                        fontSize: "0.75rem",
                        display: "block",
                        mb: 0.5,
                      }}
                    >
                      Stake
                    </Typography>
                    <Typography
                      variant="body2"
                      sx={{
                        color: DARK_THEME.palette.text.primary,
                        fontWeight: 600,
                      }}
                    >
                      {slip.currency} {analytics.stake.toFixed(2)}
                    </Typography>
                  </Box>
                </Grid>
                <Grid item xs={6} sm={3}>
                  <Box>
                    <Typography
                      variant="caption"
                      sx={{
                        color: DARK_THEME.palette.text.tertiary,
                        fontWeight: 500,
                        fontSize: "0.75rem",
                        display: "block",
                        mb: 0.5,
                      }}
                    >
                      Total Odds
                    </Typography>
                    <Typography
                      variant="body2"
                      sx={{
                        color: DARK_THEME.palette.text.primary,
                        fontWeight: 600,
                      }}
                    >
                      {analytics.totalOdds.toFixed(2)}
                    </Typography>
                  </Box>
                </Grid>
              </Grid>
            </Stack>
          </Paper>
        </Stack>

        {/* Key Metrics */}
        <Grid container spacing={3} sx={{ mb: 4 }}>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Potential Return"
              value={`${slip.currency} ${analytics.possibleReturn.toFixed(2)}`}
              subtitle={`${analytics.roi.toFixed(1)}% ROI`}
              icon={AccountBalanceWallet}
              color={DARK_THEME.palette.status.won}
            />
          </Grid>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Profit"
              value={`${slip.currency} ${analytics.potentialProfit.toFixed(2)}`}
              subtitle={`On ${analytics.stake.toFixed(2)} stake`}
              icon={TrendingUp}
              color={DARK_THEME.palette.accents.secondary}
            />
          </Grid>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Avg Odds"
              value={analytics.averageOdds.toFixed(2)}
              subtitle={`Total: ${analytics.totalOdds.toFixed(2)}`}
              icon={ShowChart}
              color={DARK_THEME.palette.accents.primary}
            />
          </Grid>
          <Grid item xs={12} sm={6} md={3}>
            <StatCard
              title="Analysis Status"
              value={
                analysisStatus.charAt(0).toUpperCase() + analysisStatus.slice(1)
              }
              subtitle={`${analytics.alternative_slips_count} alternatives`}
              icon={AutoGraph}
              color={DARK_THEME.palette.status.pending}
            />
          </Grid>
        </Grid>

        {/* ML Generation Button - CENTERPIECE */}
        <MLGenerationButton
          onRun={handleRunMLGeneration}
          loading={mlRunning}
          status={analysisStatus}
        />

        {/* Main Content */}
        <Grid container spacing={3}>
          {/* Matches Section */}
          <Grid item xs={12} md={8}>
            <Paper
              sx={{
                p: 3,
                borderRadius: DARK_THEME.shape.borderRadius.lg,
                backgroundColor: DARK_THEME.palette.background.surface1,
                border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
                height: "100%",
              }}
            >
              <Stack spacing={3}>
                <Stack
                  direction="row"
                  justifyContent="space-between"
                  alignItems="center"
                >
                  <Typography
                    variant="h5"
                    sx={{
                      fontWeight: 700,
                      color: DARK_THEME.palette.text.primary,
                    }}
                  >
                    Match Selections
                  </Typography>
                  <Chip
                    label={`${analytics.matchesCount} matches`}
                    size="small"
                    sx={{
                      fontWeight: 600,
                      bgcolor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                    }}
                  />
                </Stack>

                <Divider
                  sx={{
                    borderColor: alpha(
                      DARK_THEME.palette.background.surface3,
                      0.3
                    ),
                  }}
                />

                <Stack spacing={2}>
                  {slip.matches?.map((match, index) => (
                    <MatchCard
                      key={match.match_id}
                      match={match}
                      index={index}
                    />
                  ))}
                </Stack>
              </Stack>
            </Paper>
          </Grid>

          {/* Sidebar - Analytics & Actions */}
          <Grid item xs={12} md={4}>
            <Stack spacing={3}>
              {/* Market Distribution */}
              <Paper
                sx={{
                  p: 3,
                  borderRadius: DARK_THEME.shape.borderRadius.lg,
                  backgroundColor: DARK_THEME.palette.background.surface1,
                  border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
                }}
              >
                <Typography
                  variant="h6"
                  sx={{
                    fontWeight: 600,
                    color: DARK_THEME.palette.text.primary,
                    mb: 2,
                  }}
                >
                  Market Distribution
                </Typography>
                <Stack spacing={1.5}>
                  {Object.entries(analytics.marketDistribution).map(
                    ([market, count]) => (
                      <Stack
                        key={market}
                        direction="row"
                        alignItems="center"
                        justifyContent="space-between"
                      >
                        <Typography
                          variant="body2"
                          sx={{
                            color: DARK_THEME.palette.text.secondary,
                            flex: 1,
                          }}
                        >
                          {market}
                        </Typography>
                        <Stack
                          direction="row"
                          alignItems="center"
                          spacing={1}
                          sx={{ minWidth: 80 }}
                        >
                          <Box sx={{ flex: 1 }}>
                            <LinearProgress
                              variant="determinate"
                              value={(count / analytics.matchesCount) * 100}
                              sx={{
                                height: 6,
                                borderRadius:
                                  DARK_THEME.shape.borderRadius.pill,
                                bgcolor: DARK_THEME.palette.background.surface3,
                                "& .MuiLinearProgress-bar": {
                                  bgcolor: DARK_THEME.palette.accents.primary,
                                  borderRadius:
                                    DARK_THEME.shape.borderRadius.pill,
                                },
                              }}
                            />
                          </Box>
                          <Typography
                            variant="body2"
                            sx={{
                              color: DARK_THEME.palette.text.primary,
                              fontWeight: 600,
                              minWidth: 24,
                              textAlign: "right",
                            }}
                          >
                            {count}
                          </Typography>
                        </Stack>
                      </Stack>
                    )
                  )}
                </Stack>
              </Paper>

              {/* Quick Actions */}
              <Paper
                sx={{
                  p: 3,
                  borderRadius: DARK_THEME.shape.borderRadius.lg,
                  backgroundColor: DARK_THEME.palette.background.surface1,
                  border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
                }}
              >
                <Typography
                  variant="h6"
                  sx={{
                    fontWeight: 600,
                    color: DARK_THEME.palette.text.primary,
                    mb: 2,
                  }}
                >
                  Quick Actions
                </Typography>
                <Stack spacing={1}>
                  <Button
                    fullWidth
                    startIcon={<AutoGraph />}
                    variant="outlined"
                    onClick={() => navigate(`/generatedslips/${id}/slips`)} // This is the key line
                    sx={{
                      justifyContent: "flex-start",
                      borderColor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                      py: 1.5,
                      "&:hover": {
                        borderColor: DARK_THEME.palette.accents.primary,
                        color: DARK_THEME.palette.text.primary,
                      },
                    }}
                  >
                    View Generated Slips
                  </Button>
                  <Button
                    fullWidth
                    startIcon={<CompareArrows />}
                    variant="outlined"
                    sx={{
                      justifyContent: "flex-start",
                      borderColor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                      py: 1.5,
                      "&:hover": {
                        borderColor: DARK_THEME.palette.accents.primary,
                        color: DARK_THEME.palette.text.primary,
                      },
                    }}
                  >
                    Compare with Similar
                  </Button>
                  <Button
                    fullWidth
                    startIcon={<Psychology />}
                    variant="outlined"
                    sx={{
                      justifyContent: "flex-start",
                      borderColor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                      py: 1.5,
                      "&:hover": {
                        borderColor: DARK_THEME.palette.accents.primary,
                        color: DARK_THEME.palette.text.primary,
                      },
                    }}
                  >
                    View AI Insights
                  </Button>
                  <Button
                    fullWidth
                    startIcon={<QueryStats />}
                    variant="outlined"
                    sx={{
                      justifyContent: "flex-start",
                      borderColor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                      py: 1.5,
                      "&:hover": {
                        borderColor: DARK_THEME.palette.accents.primary,
                        color: DARK_THEME.palette.text.primary,
                      },
                    }}
                  >
                    Advanced Analytics
                  </Button>
                </Stack>
              </Paper>

              {/* Advanced Metrics */}
              {analytics.alternative_slips_count > 0 && (
                <Paper
                  sx={{
                    p: 3,
                    borderRadius: DARK_THEME.shape.borderRadius.lg,
                    backgroundColor: DARK_THEME.palette.background.surface1,
                    border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
                  }}
                >
                  <Stack
                    direction="row"
                    alignItems="center"
                    justifyContent="space-between"
                    spacing={2}
                  >
                    <Box>
                      <Typography
                        variant="h6"
                        sx={{
                          fontWeight: 600,
                          color: DARK_THEME.palette.text.primary,
                          mb: 0.5,
                        }}
                      >
                        ML Results
                      </Typography>
                      <Typography
                        variant="body2"
                        sx={{
                          color: DARK_THEME.palette.text.secondary,
                        }}
                      >
                        {analytics.alternative_slips_count} optimized slip
                        {alternative_slips_count > 1 ? "s" : ""} generated
                      </Typography>
                    </Box>
                    <Chip
                      label="New"
                      size="small"
                      sx={{
                        bgcolor: alpha(DARK_THEME.palette.status.won, 0.1),
                        color: DARK_THEME.palette.status.won,
                        fontWeight: 700,
                      }}
                    />
                  </Stack>
                </Paper>
              )}
            </Stack>
          </Grid>
        </Grid>

        {/* Tabs Section */}
        <Paper
          sx={{
            mt: 4,
            borderRadius: DARK_THEME.shape.borderRadius.lg,
            backgroundColor: DARK_THEME.palette.background.surface1,
            border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.5)}`,
          }}
        >
          <Tabs
            value={activeTab}
            onChange={(e, newValue) => setActiveTab(newValue)}
            sx={{
              borderBottom: 1,
              borderColor: alpha(DARK_THEME.palette.background.surface3, 0.3),
              px: 3,
              pt: 2,
              "& .MuiTab-root": {
                color: DARK_THEME.palette.text.secondary,
                textTransform: "none",
                fontWeight: 500,
                fontSize: "0.9rem",
                "&.Mui-selected": {
                  color: DARK_THEME.palette.text.primary,
                  fontWeight: 600,
                },
              },
            }}
          >
            <Tab
              label="Performance Analysis"
              icon={<Timeline />}
              iconPosition="start"
            />
            <Tab
              label="Risk Assessment"
              icon={<Security />}
              iconPosition="start"
            />
            <Tab
              label="Historical Data"
              icon={<History />}
              iconPosition="start"
            />
            <Tab
              label="Export Options"
              icon={<Download />}
              iconPosition="start"
            />
          </Tabs>

          <Box sx={{ p: 3 }}>
            <TabPanel value={activeTab} index={0}>
              <Stack spacing={2}>
                <Typography
                  variant="h6"
                  sx={{
                    color: DARK_THEME.palette.text.primary,
                    fontWeight: 600,
                    mb: 1,
                  }}
                >
                  Performance Insights
                </Typography>
                <Typography
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    mb: 3,
                  }}
                >
                  Detailed performance metrics, predictive analysis, and
                  AI-generated insights will appear here. The system analyzes
                  historical patterns, current form, and market conditions to
                  provide comprehensive performance forecasting.
                </Typography>
                {insights?.performance && (
                  <Paper
                    sx={{
                      p: 2,
                      backgroundColor: DARK_THEME.palette.background.surface2,
                      border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.3)}`,
                    }}
                  >
                    <Typography
                      variant="body2"
                      sx={{ color: DARK_THEME.palette.text.secondary }}
                    >
                      {insights.performance.summary}
                    </Typography>
                  </Paper>
                )}
              </Stack>
            </TabPanel>
            <TabPanel value={activeTab} index={1}>
              <Stack spacing={2}>
                <Typography
                  variant="h6"
                  sx={{
                    color: DARK_THEME.palette.text.primary,
                    fontWeight: 600,
                    mb: 1,
                  }}
                >
                  Risk Assessment
                </Typography>
                <Typography
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    mb: 3,
                  }}
                >
                  Comprehensive risk analysis including volatility metrics,
                  correlation analysis, and probability distributions. The
                  system evaluates market conditions, team form, and historical
                  performance to quantify risk levels.
                </Typography>
                <Grid container spacing={2}>
                  <Grid item xs={6}>
                    <Box sx={{ textAlign: "center" }}>
                      <Typography
                        variant="caption"
                        sx={{
                          color: DARK_THEME.palette.text.tertiary,
                          display: "block",
                        }}
                      >
                        Analysis Status
                      </Typography>
                      <Typography
                        variant="h6"
                        sx={{
                          color: DARK_THEME.palette.text.primary,
                          fontWeight: 700,
                        }}
                      >
                        {analysisStatus}
                      </Typography>
                    </Box>
                  </Grid>
                  <Grid item xs={6}>
                    <Box sx={{ textAlign: "center" }}>
                      <Typography
                        variant="caption"
                        sx={{
                          color: DARK_THEME.palette.text.tertiary,
                          display: "block",
                        }}
                      >
                        Risk Level
                      </Typography>
                      <Typography
                        variant="h6"
                        sx={{
                          color:
                            analytics.averageOdds > 2
                              ? DARK_THEME.palette.status.lost
                              : DARK_THEME.palette.status.won,
                          fontWeight: 700,
                        }}
                      >
                        {analytics.averageOdds > 2 ? "High" : "Low"}
                      </Typography>
                    </Box>
                  </Grid>
                </Grid>
              </Stack>
            </TabPanel>
            <TabPanel value={activeTab} index={2}>
              <Stack spacing={2}>
                <Typography
                  variant="h6"
                  sx={{
                    color: DARK_THEME.palette.text.primary,
                    fontWeight: 600,
                    mb: 1,
                  }}
                >
                  Historical Data
                </Typography>
                <Typography
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    mb: 3,
                  }}
                >
                  Historical performance data, trend analysis, and comparative
                  statistics. View past performance patterns, success rates by
                  market type, and historical ROI analysis for similar slip
                  configurations.
                </Typography>
                {insights?.historical && (
                  <Paper
                    sx={{
                      p: 2,
                      backgroundColor: DARK_THEME.palette.background.surface2,
                      border: `1px solid ${alpha(DARK_THEME.palette.background.surface3, 0.3)}`,
                    }}
                  >
                    <Typography
                      variant="body2"
                      sx={{ color: DARK_THEME.palette.text.secondary }}
                    >
                      Historical analysis based on{" "}
                      {insights.historical.match_count || 0} similar historical
                      matches.
                    </Typography>
                  </Paper>
                )}
              </Stack>
            </TabPanel>
            <TabPanel value={activeTab} index={3}>
              <Stack spacing={2}>
                <Typography
                  variant="h6"
                  sx={{
                    color: DARK_THEME.palette.text.primary,
                    fontWeight: 600,
                    mb: 1,
                  }}
                >
                  Export Options
                </Typography>
                <Typography
                  sx={{
                    color: DARK_THEME.palette.text.secondary,
                    mb: 3,
                  }}
                >
                  Export slip analysis in multiple formats for further analysis,
                  reporting, or sharing with team members.
                </Typography>
                <Stack
                  direction="row"
                  spacing={2}
                  sx={{ flexWrap: "wrap", gap: 2 }}
                >
                  <Button
                    startIcon={<Download />}
                    variant="outlined"
                    sx={{
                      borderColor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                      "&:hover": {
                        borderColor: DARK_THEME.palette.accents.primary,
                        color: DARK_THEME.palette.text.primary,
                      },
                    }}
                  >
                    Export as PDF
                  </Button>
                  <Button
                    startIcon={<Download />}
                    variant="outlined"
                    sx={{
                      borderColor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                      "&:hover": {
                        borderColor: DARK_THEME.palette.accents.primary,
                        color: DARK_THEME.palette.text.primary,
                      },
                    }}
                  >
                    Export as CSV
                  </Button>
                  <Button
                    startIcon={<Share />}
                    variant="outlined"
                    sx={{
                      borderColor: alpha(
                        DARK_THEME.palette.background.surface3,
                        0.5
                      ),
                      color: DARK_THEME.palette.text.secondary,
                      "&:hover": {
                        borderColor: DARK_THEME.palette.accents.primary,
                        color: DARK_THEME.palette.text.primary,
                      },
                    }}
                  >
                    Share via API
                  </Button>
                </Stack>
              </Stack>
            </TabPanel>
          </Box>
        </Paper>
      </Container>
    </Box>
  );
};

export default MasterSlipAnalysisPage;
